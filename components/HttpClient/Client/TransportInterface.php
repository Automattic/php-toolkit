<?php

namespace WordPress\HttpClient\Client;

use WordPress\HttpClient\Request;

interface TransportInterface {

	public function event_loop_tick(): bool;

}