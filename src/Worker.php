<?php

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

        if (is_null($obj->statusUrl)) {
            return;
        }

        echo 'processing '.$obj->statusUrl.PHP_EOL;

        $this->setEventStatus($obj, self::STATUS_WORKING);

        // send to hipchat
        $text = 'I received an event on <i>'.$obj->event.'</i>. The status I updated is '.$obj->statusUrl. ' - '.
            'the affected document lies at '.$obj->publicUrl;

        $notification = new \stdClass();
        $notification->color = 'green';
        $notification->message = $text;
        $notification->notify = true;

        try {
            Request::post(
                'https://api.hipchat.com/v2/room/1914209/notification?' .
                'auth_token=xM4CA5Fc9FZtjRFyOADFv57Ln7hVO4kjlfzkJ0t8'
            )->sendsJson()
                ->body($notification)
                ->send();
        } catch (\Exception $e) {
            $this->setEventStatus($obj, self::STATUS_FAILED, 'Could not POST to hipchat.com');
            return;
        }

        $this->setEventStatus($obj, self::STATUS_DONE);

        echo 'FINISHED processing '.$obj->statusUrl.' (memory = '.number_format(memory_get_usage() / 1024) . ' KiB' . PHP_EOL;

        gc_collect_cycles();
        file_put_contents(__DIR__.'mem', number_format(memory_get_usage() / 1024) . ' KiB' . PHP_EOL, FILE_APPEND);

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
            $statusObj = Request::get($obj->statusUrl)
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

                Request::put($obj->statusUrl)
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
