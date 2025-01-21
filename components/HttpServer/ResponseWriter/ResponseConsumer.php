<?php

namespace WordPress\HttpServer\ResponseWriter;

use WordPress\ByteStream\Writer\ByteConsumer;

interface ResponseConsumer extends ByteConsumer {

    public function send_http_code($code);
    public function send_header($name, $value);

}
