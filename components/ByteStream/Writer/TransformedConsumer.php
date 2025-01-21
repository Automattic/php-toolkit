<?php
namespace WordPress\ByteStream\Writer;

use ArrayAccess;
use WordPress\ByteStream\Transformer\ByteTransformer;

class TransformedConsumer implements ByteConsumer, ArrayAccess {

    /**
     * @var ByteConsumer
     */
    private $writer;

    /**
     * @var ByteTransformer[]
     */
    private $filters = [];

    public function __construct(ByteConsumer $writer, array $filters = []) {
        $this->writer = $writer;
        $this->filters = $filters;
    }

    public function append_bytes(string $chunk): void {
        foreach($this->filters as $filter) {
            $chunk = $filter->filter_bytes($chunk);
            if($chunk === false) {
                return;
            }
        }

        $this->writer->append_bytes($chunk);
    }

    public function get_downstream_writer(): ByteConsumer {
        return $this->writer;
    }

    public function close(): void {
        foreach($this->filters as $filter) {
            $this->writer->append_bytes(
                $filter->flush()
            );
        }
    }

    /** @disregard P1038 */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset) {
        if(!isset($this->filters[$offset])) {
            throw new ByteStreamException(sprintf('Filter %s not found', $offset));
        }
        return $this->filters[$offset];
    }

    /** @disregard P1038 */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset) {
        return isset($this->filters[$offset]);
    }

    /** @disregard P1038 */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value) {
        $this->filters[$offset] = $value;
    }

    /** @disregard P1038 */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset) {
        unset($this->filters[$offset]);
    }

}
