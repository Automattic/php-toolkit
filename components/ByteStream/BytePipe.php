<?php

namespace WordPress\ByteStream;

use WordPress\ByteStream\Producer\ByteProducer;
use WordPress\ByteStream\Writer\ByteConsumer;

interface BytePipe extends ByteProducer, ByteConsumer {

}
