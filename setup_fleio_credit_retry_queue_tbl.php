<?php

require_once __DIR__ . '/../../../init.php';

use Illuminate\Database\Capsule\Manager as Capsule;

// IMPORTANT: ensure this is only executed via the command line (CLI)
if (php_sapi_name() !== 'cli') {
    die("Nothing to do\n");
}

function fleio_setup_credit_retry_queue_table() {
    try {
        if (!Capsule::schema()->hasTable('fleio_credit_retry_queue')) {
            echo 'Creating the "fleio_credit_retry_queue" table ...';
            echo "\r\n";
            Capsule::schema()->create('fleio_credit_retry_queue', function ($table) {
                $table->increments('id');
                $table->string('invoice_id', 64);
                $table->string('service_id', 64);
                $table->string('item_id', 64);
                $table->boolean('subtract')->default(false);
                $table->string('status', 20)->default('pending'); // pending, processing, failed, failed_permanently
                $table->integer('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('next_attempt_at')->useCurrent();
                $table->timestamps(); // creates created_at and updated_at automatically
                // add indexes for status and next_attempt_at
                $table->index(['status', 'invoice_id', 'next_attempt_at']);
            });
            echo 'Table "fleio_credit_retry_queue" was created!';
            echo "\r\n";
        } else {
            echo 'The table "fleio_credit_retry_queue" already exists, nothing to do!';
            echo "\r\n";
        }
    } catch (Exception $e) {
        echo 'Error while creating table: '.$e->getMessage();
        echo "\r\n";
    }
}

fleio_setup_credit_retry_queue_table();

?>
