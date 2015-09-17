<?php
/**
 * example worker showing how to use graviton events
 */

namespace Graviton\Worker;

use Httpful\Request;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * @author   List of contributors <https://github.com/libgraviton/import-export/graphs/contributors>
 * @license  http://opensource.org/licenses/gpl-license.php GNU Public License
 * @link     http://swisscom.ch
 */
class Worker
{
    /**
     * constant for working status
     */
    const STATUS_WORKING = 'working';

    /**
     * constant for done status
     */
    const STATUS_DONE = 'done';

    /**
     * constant for failed status
     */
    const STATUS_FAILED = 'failed';

    /**
     * @var array configuration
     */
    private $configuration;

    /**
     * @param array $configuration configuration
     */
    public function __construct(
        array $configuration
    ) {
        $this->configuration = $configuration;
    }

    /**
     * Runs the worker and starts listening on the queue.
     *
     * @return void
     */
    public function run()
    {
        $this->registerOurself();
        $this->connectToQueue();
    }

    /**
     * Action callback on message receiving. It should do some shell_exec, at the moment it sends some
     * stuff to a hipchat channel
     *
     * @param string $msg message
     *
     * @return void
     */
    public function receiveMessage($msg)
    {
        $obj = json_decode($msg->body);

        if (is_null($obj->status->{'$ref'})) {
            return;
        } else {
            $statusUrl = $obj->status->{'$ref'};
        }

        echo 'processing '.$statusUrl.PHP_EOL;

        // first, always set status to 'working'!
        $this->setEventStatus($obj, self::STATUS_WORKING);

        try {
            // implement your logic - here we will just print the affected document
            $document = Request::get($obj->document->{'$ref'})
                ->send();

            echo 'DOCUMENT '.$obj->document->{'$ref'}.' = '.json_encode($document->body).PHP_EOL;
        } catch (\Exception $e) {
            // catch your exceptions
            $this->setEventStatus($obj, self::STATUS_FAILED, 'Some error happened!');
            return;
        }

        // set status to 'done'
        $this->setEventStatus($obj, self::STATUS_DONE);

        echo 'FINISHED processing '.$statusUrl.' (memory = '.number_format(memory_get_usage() / 1024) . ' KiB' . PHP_EOL;

        gc_collect_cycles();

        return;
    }

    /**
     * Connects to the queue - main loop is here
     *
     * @return void
     */
    private function connectToQueue()
    {
        $connection = new AMQPStreamConnection(
            $this->getSetting('host'),
            $this->getSetting('port'),
            $this->getSetting('user'),
            $this->getSetting('password'),
            $this->getSetting('vhost')
        );
        $channel = $connection->channel();

        list($queueName, ,) = $channel->queue_declare('worker-shell-exec', false, false, true, false);

        $channel->exchange_declare($this->getSetting('exchangeName'), 'topic', false, true, false);

        $channel->queue_bind(
            $queueName,
            $this->getSetting('exchangeName'),
            $this->getSetting('routingKey')
        );

        $channel->basic_consume($queueName, '', false, true, false, false, array($this, 'receiveMessage'));

        while (count($channel->callbacks)) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    }

    /**
     * gets a setting
     *
     * @param string $key setting key
     *
     * @return mixed null or the setting value
     */
    private function getSetting($key)
    {
        if (isset($this->configuration[$key])) {
            return $this->configuration[$key];
        }
        return null;
    }

    /**
     * updates the status of our EventStatus on the backend
     *
     * @param \stdClass $obj      EventStatus object
     * @param string    $status   status
     * @param string    $errorMsg error message
     *
     * @throws \Httpful\Exception\ConnectionErrorException
     *
     * @return void
     */
    private function setEventStatus($obj, $status, $errorMsg = null)
    {
        try {
            $statusObj = Request::get($obj->status->{'$ref'})
                ->send();

            $workerId = $this->getSetting('workerId');

            if (is_array($statusObj->body->status)) {
                foreach ($statusObj->body->status as $key => $singleStatus) {
                    if ($singleStatus->workerId == $workerId) {
                        $statusObj->body->status[$key]->status = $status;
                    }
                }

                // error information?
                if (!is_null($errorMsg)) {
                    $statusObj->body->errorInformation[$workerId] = $errorMsg;
                }

                Request::put($obj->status->{'$ref'})
                    ->sendsJson()
                    ->body($statusObj->body)
                    ->send();
            }
        } catch (\Exception $e) {
            // what should we do? could not update status..
            // but we cannot let the worker die..
        }
    }

    /**
     * Register ourself with the backend as a worker
     *
     * @return void
     */
    private function registerOurself()
    {
        $registerObj = new \stdClass();
        $registerObj->id = $this->getSetting('workerId');

        foreach ($this->getSetting('subscriptions') as $subName) {
            $sub = new \stdClass();
            $sub->event = $subName;
            $registerObj->subscription[] = $sub;
        }

        Request::put($this->getSetting('registerUrl').$this->getSetting('workerId'))
            ->sendsJson()
            ->body($registerObj)
            ->send();
    }
}
