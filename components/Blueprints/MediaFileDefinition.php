<?php

namespace WordPress\Blueprints;

use WordPress\Blueprints\DataReference\DataReference;

class MediaFileDefinition {
	public DataReference $source;
	public ?string $title = null;
	public ?string $description = null;
	public ?string $alt = null;
	public ?string $caption = null;

	static public function fromArray( array $data ): self {
		$instance              = new self();
		$instance->source      = $data['source'];
		$instance->title       = $data['title'] ?? null;
		$instance->description = $data['description'] ?? null;
		$instance->alt         = $data['alt'] ?? null;
		$instance->caption     = $data['caption'] ?? null;

		return $instance;
	}
}
