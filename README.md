# graviton-worker-example

This is a simple example of a PHP based Graviton queue worker. Please see the documentation on Graviton events.

## How to use

Clone this repo, then run:

```
composer install
```

Review the settings in the file `run.php`. There the configuration of the worker is done. Make sure there is a RabbitMQ present where you
want to connect and that the URL pointing to Graviton is correct. Also note that if there is no Graviton connecting to same exchange, you will 
probably not receive any messages ;-)

If everything is properly configured, just run the worker:

```
php run.php
```

The worker will show with simple messages to STDOUT that it received a message.
