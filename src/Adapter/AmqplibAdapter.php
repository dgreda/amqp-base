<?php
/**
 * @todo implement dynamic naming for queues
 */
namespace Amqp\Adapter;

use Amqp\Adapter\Amqplib\Helper\Exception as ExceptionHelper;
use Amqp\Message\MessageInterface;
use Amqp\Message\Result;
use Amqp\Adapter\Amqplib\Helper\Message as MessageHelper;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Wire\AMQPTable;

class AmqplibAdapter extends AbstractAdapter
{

    /**
     * The final processed configuration for connections, queues and exchanges
     *
     * @var array
     */
    protected $finalConfig = array(
        'connections' => array(),
        'exchanges' => array(),
        'queues' => array(),
    );

    /**
     * The currently opened channels
     *
     * @var AMQPChannel[]
     */
    protected $channels = array();

    /**
     * Store the exchange names which should be present
     *
     * @var array
     */
    protected $exchanges = array();

    /**
     * The current message counters
     *
     * @var array
     */
    protected $counters = array();

    /**
     * Temporarily mark all dependencies here, so we can have a refcount for all of them
     *
     * @var array
     */
    protected $dependenciesCounter = array(
        'exchanges' => array(),
        'queues'    => array(),
    );

    /**
     * @param string $exchangeName
     * @param MessageInterface $message
     * @param null $routingKey
     * @return void
     * @throws \Exception
     */
    public function publish($exchangeName, MessageInterface $message, $routingKey = null)
    {
        try {
            $exchangeConfig = $this->exchangeConfig($exchangeName);
            $connectionName = $exchangeConfig['connection'];
            $channel = $this->channel($connectionName);

            $this->declareExchange($channel, $exchangeName);


            $channel->basic_publish(
                MessageHelper::toAMQPMessage($message),
                $exchangeConfig['name'],
                $routingKey !== null ? $routingKey : null
            );

            if ($this->finalConfig['connections'][$connectionName]['publisher_confirms']) {
                $channel->wait_for_pending_acks_returns();
            }

        } catch (\Exception $e) {
            throw ExceptionHelper::convert($e);
        }
    }

    /**
     * Listen for incoming messages
     *
     * @param string $queueName  The name of the queue to be used
     * @param callable $callback The callback from userland to be used. Accepts one parameter, message
     * @param array $options     The set of options to pass to the listening [multi_ack => false]
     * @throws ChannelException
     * @throws ConnectionException
     * @throws \Exception
     */
    public function listen($queueName, callable $callback, array $options = array())
    {
        try {
            $stop = false;
            $options = array_merge($this->defaultConfig['listener'], $options);
            $queueConfig = $this->queueConfig($queueName);

            if ($options['multi_ack'] == true) {
                // acknowledge at prefetch_count / 2 if prefetch count is set
                $connectionName = $this->finalConfig['queues'][$queueName]['connection'];
                $properties = $this->connectionConfig($connectionName);

                // force acknowledgements at ceil of quality of service (channel property)
                $ackAt = (int) ceil($properties['prefetch_count'] / 2);
            } else {
                $ackAt = 0;
            }

            $channel = $this->channel($this->finalConfig['queues'][$queueName]['connection']);
            $this->declareQueue($channel, $queueName);

            $this->counters[spl_object_hash($channel)] = 0;
            $internalCallback = \Closure::bind(function ($message) use ($channel, $callback, $options, $ackAt, &$stop) {
                $result = new Result();
                call_user_func($callback, MessageHelper::toMessage($message), $result);

                if ($result->getStatus()) {
                    if ($ackAt !== 0) {
                        if (++$this->counters[spl_object_hash($channel)] === $ackAt) {
                            // multiple acknowledgements
                            $channel->basic_ack($message->delivery_info['delivery_tag'], true);
                            $this->counters[spl_object_hash($channel)] = 0;
                        }
                    } elseif ($options['auto_ack'] === false) {
                        $channel->basic_ack($message->delivery_info['delivery_tag']);
                    }
                } else {
                    $channel->basic_nack($message->delivery_info['delivery_tag'], false, $result->isRequeue());
                }

                $stop = $result->isStop();
            }, $this);

            $channel->basic_consume(
                $queueConfig['name'],                       // $queue
                '',                                         // $consumer_tag
                false,                                      // $no_local
                $options['auto_ack'],                       // $no_ack
                false,                                      // $exclusive
                false,                                      // $nowait
                $internalCallback,                          // $callback
                null,                                       // $ticket
                new AMQPTable($queueConfig['arguments'])    // $arguments
            );

            while (!$stop) {
                $channel->wait();
            }
        } catch (\Exception $e) {
            throw ExceptionHelper::convert($e);
        }
    }

    /**
     * Initialize a connection
     *
     * @param string $name The name of the connection configuration to be used
     *
     * @return AMQPChannel
     */
    protected function channel($name)
    {
        // check cache for channel
        if (isset($this->channels[$name])) {
            // is the connection still up?
            if ($this->channels[$name]->getConnection()->isConnected()) {
                // return it
                return $this->channels[$name];
            }
        }

        // if the connection is no longer up, remake it
        $config = $this->connectionConfig($name);
        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['login'],
            $config['password'],
            $config['vhost'],
            false,
            'AMQPLAIN',
            null,
            'en_US',
            $config['connect_timeout'],
            $config['read_write_timeout'],
            null,
            $config['keepalive'],
            $config['heartbeat']
        );

        // get the channel
        $channel = $connection->channel();
        $channel->basic_qos(0, $config['prefetch_count'], false);

        if ($config['publisher_confirms']) {
            $channel->confirm_select();
        }

        $this->channels[$name] = $channel;

        return $channel;
    }

    /**
     * Declares a queue with all its dependencies
     *
     * @param AMQPChannel $channel The open channel on which the queue should be declared
     * @param string $queueName    The queue name
     *
     * @return bool
     */
    protected function declareQueue(AMQPChannel $channel, $queueName)
    {
        $this->detectCircularDependencies('queue', $queueName);

        $options = $this->queueConfig($queueName);

        // increase dependency counter
        if (!isset($this->dependenciesCounter['queues'][$queueName])) {
            $this->dependenciesCounter['queues'][$queueName] = 0;
        }
        $this->dependenciesCounter['queues'][$queueName] += 1;

        if (isset($options['arguments']['x-dead-letter-exchange'])) {
            $deadLetterExchange = $options['arguments']['x-dead-letter-exchange'];
            $this->declareExchange($channel, $deadLetterExchange);
        }

        $result = $channel->queue_declare(
            $options['name'],
            $options['passive'],                    // $passive
            $options['durable'],                    // $durable
            $options['exclusive'],                  // $exclusive
            $options['auto_delete'],                // $auto_delete
            false,                                  // $nowait
            new AMQPTable($options['arguments']),   // $arguments
            null                                    // $ticket
        );

        // check for bindings/dependencies
        foreach ($options['bindings'] as $bind) {
            $this->declareExchange($channel, $bind['exchange']);
            $exchangeName = $this->finalConfig['exchanges'][$bind['exchange']]['name'];
            $channel->queue_bind($options['name'], $exchangeName, $bind['routing_key']);
        }

        // remove the dependency since we reached this step
        unset($this->dependenciesCounter['queues'][$queueName]);

        return $result;
    }

    /**
     * Declare an exchange
     *
     * @param AMQPChannel $channel The channel on which the exchange needs to be declared
     * @param string $exchangeName The name of the exchange to be declared
     *
     * @return bool
     */
    protected function declareExchange(AMQPChannel $channel, $exchangeName)
    {
        if (isset($this->exchanges[$exchangeName])) {
            return false;
        }

        $options = $this->exchangeConfig($exchangeName);
        $this->detectCircularDependencies('exchange', $exchangeName);

        if (!isset($this->dependenciesCounter['exchanges'][$exchangeName])) {
            $this->dependenciesCounter['exchanges'][$exchangeName] = 0;
        }
        $this->dependenciesCounter['exchanges'][$exchangeName] += 1;

        // check for all dependencies
        if (isset($options['bindings'])) {
            foreach ($options['bindings'] as $bind) {
                $this->declareExchange($channel, $bind['exchange']);
                $sourceName = $this->finalConfig['exchanges'][$bind['exchange']]['name'];
                $channel->exchange_bind($exchangeName, $sourceName, $bind['routing_key']);
            }
        }

        if (isset($options['arguments']['alternate-exchange'])) {
            $alternateExchange = $options['arguments']['alternate-exchange'];
            $this->declareExchange($channel, $alternateExchange);
            $options['arguments']['alternate-exchange'] = $this->finalConfig['exchanges'][$alternateExchange]['name'];
        }

        $result = $channel->exchange_declare(
            $options['name'],                       // $name
            $options['type'],                       // $type
            $options['passive'],                    // $passive
            $options['durable'],                    // $durable
            false,                                  // $auto_delete
            false,                                  // $internal
            false,                                  // $nowait
            new AMQPTable($options['arguments']),   // $arguments
            null                                    // $ticket
        );

        $this->exchanges[$exchangeName] = true;

        unset($this->dependenciesCounter['exchanges'][$exchangeName]);
        return $result;
    }

    /**
     * Retrieves and converts a generic configuration for a connection
     *
     * @param string $name The name of the connection to use
     *
     * @return array
     */
    protected function connectionConfig($name)
    {
        // if we have the connection config cached, return it
        if (isset($this->finalConfig['connections'][$name])) {
            return $this->finalConfig['connections'][$name];
        }

        if (!isset($this->config['connections'][$name])) {
            throw new \InvalidArgumentException("Connection '{$name}' doesn't exists.");
        }

        // convert the config for this connection
        $localConfig = $this->config['connections'][$name];
        if (isset($localConfig['read_timeout']) && isset($localConfig['write_timeout'])) {
            $localConfig['read_write_timeout'] = min($localConfig['read_timeout'], $localConfig['write_timeout']);
        }

        $config = array_merge($this->defaultConfig['connection'], $localConfig);

        $rwt = $config['read_write_timeout'];
        $hb = $this->defaultConfig['connection']['heartbeat'];

        // @todo investigate why the connection times out if the read_write_timeout is less than heartbeat * 2
        if ($rwt < $hb*2) {
            $config['read_write_timeout'] = $hb*2;
        }

        $this->finalConfig['connections'][$name] = $config;


        return $config;
    }

    /**
     * Returns the processed queue config
     *
     * @param string $name The configuration name for the queue
     *
     * @return array
     */
    protected function queueConfig($name)
    {
        // config exists already in the cache, return it immediately
        if (isset($this->finalConfig['queues'][$name])) {
            return $this->finalConfig['queues'][$name];
        }

        if (!isset($this->config['queues'][$name])) {
            throw new \InvalidArgumentException("Queue '{$name}' doesn't exists.");
        }

        return $this->finalConfig['queues'][$name] = $this->getConfig('queue', $name);
    }

    /**
     * Prepares the configuration for exchange
     *
     * @param string $name The name of the exchange from the config
     *
     * @return array
     */
    protected function exchangeConfig($name)
    {
        // config exists already in the cache, return it immediately
        if (isset($this->finalConfig['exchanges'][$name])) {
            return $this->finalConfig['exchanges'][$name];
        }

        if (!isset($this->config['exchanges'][$name])) {
            throw new \InvalidArgumentException("Exchange '{$name}' doesn't exists.");
        }

        return $this->finalConfig['exchanges'][$name] = $this->getConfig('exchange', $name);
    }

    /**
     * Attempts to detect circular dependencies in config declarations
     *
     * @param string $type Type of the current dependency [queue, exchange]
     * @param string $name The name of the dependency
     * @throws \Exception if a cyclic dependency is detected
     */
    protected function detectCircularDependencies($type, $name)
    {
        if (isset($this->dependenciesCounter[$type][$name])) {
            if ($this->dependenciesCounter[$type][$name] > 0) {
                throw new \Exception("Circular dependencies detected!");
            }
        } else {
            $this->dependenciesCounter[$type][$name] = 0;
        }
    }

    public function __destruct()
    {
        /** @var AbstractConnection[] $connections */
        $connections = [];
        foreach ($this->channels as $channel) {
            $connection = $channel->getConnection();
            $connections[spl_object_hash($connection)] = $connection;
            $channel->close();
        }

        foreach ($connections as $connection) {
            $connection->close();
        }
    }
}