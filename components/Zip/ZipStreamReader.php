<?php

namespace WordPress\Zip;

use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\NotEnoughDataException;
use WordPress\ByteStream\Producer\ByteProducer;
use WordPress\ByteStream\Producer\InflateProducer;
use WordPress\ByteStream\Producer\LimitProducer;
use WordPress\ByteStream\Producer\ReaderUtils;

class ZipStreamReader {

	const COMPRESSION_DEFLATE = 8;
	const COMPRESSION_NONE = 0;

	const STATE_SCAN = 'scan';
	const STATE_FILE_ENTRY = 'file-entry';
	const STATE_CENTRAL_DIRECTORY_ENTRY_READING = 'central-directory-entry-reading';
	const STATE_END_CENTRAL_DIRECTORY_ENTRY_READING = 'end-central-directory-entry-reading';
	const STATE_OBJECT_READY = 'object-ready';
	const STATE_COMPLETE = 'complete';

	private $state = ZipStreamReader::STATE_SCAN;
	private $object = null;
	private $byte_reader;
	private $paused_at_incomplete_input = false;

	public function __construct(ByteProducer $byte_reader) {
		$this->byte_reader = $byte_reader;
	}

	public function is_paused_at_incomplete_input(): bool {
		return $this->paused_at_incomplete_input;
	}

	public function reached_end_of_data(): bool {
		return self::STATE_COMPLETE === $this->state;
	}

	public function next_object(): bool {
        $this->paused_at_incomplete_input = false;
        try {
            // If we're calling next_object() when an object is ready,
            // it means we want to scan for the next object. Let's clear
            // the state and start scanning again.
            if($this->state === self::STATE_OBJECT_READY) {
                $this->after_record();
            }

            while(true) {
                switch ($this->state) {
                    case self::STATE_SCAN:
                        $signature = ReaderUtils::read_exactly_n_bytes($this->byte_reader, 4);
                        $signature = unpack('V', $signature)[1];
                        switch($signature) {
                            case FileEntry::SIGNATURE:
                                $this->state = self::STATE_FILE_ENTRY;
                                break;
                            case CentralDirectoryEntry::SIGNATURE:
                                $this->state = self::STATE_CENTRAL_DIRECTORY_ENTRY_READING;
                                break;
                            case EndCentralDirectoryEntry::SIGNATURE:
                                $this->state = self::STATE_END_CENTRAL_DIRECTORY_ENTRY_READING;
                                break;
                            default:
                                throw new ByteStreamException(
                                    sprintf('Invalid ZIP object signature %d', $signature),
                                );
                        }
                        break;

                    case self::STATE_FILE_ENTRY:
                        $this->read_file_entry();
                        break;

                    case self::STATE_CENTRAL_DIRECTORY_ENTRY_READING:
                        $this->read_central_directory_entry();
                        break;

                    case self::STATE_END_CENTRAL_DIRECTORY_ENTRY_READING:
                        $this->read_end_central_directory_entry();
                        break;

                    case self::STATE_OBJECT_READY:
                        return true;

                    default:
                        return false;
                }
            }
        } catch (NotEnoughDataException $e) {
            $this->paused_at_incomplete_input = true;
            return false;
        }
	}

	public function get_object() {
        return $this->object;
	}

	public function seek_to_record($record_offset) {
		$this->after_record();
		$this->byte_reader->seek($record_offset);
	}

    private function read_file_entry() {
		if (!$this->object) {
            $data = ReaderUtils::read_exactly_n_bytes($this->byte_reader, FileEntry::HEADER_SIZE);
            $header_fields = unpack(
                'vversion/vgeneralPurpose/vcompressionMethod/vlastModifiedTime/vlastModifiedDate/Vcrc/VcompressedSize/VuncompressedSize/vpathLength/vextraLength',
                $data
            );
            $this->object = new FileEntry($header_fields);
        }

        if(null === $this->object->path) {
            $path = ReaderUtils::read_exactly_n_bytes($this->byte_reader, $this->object->pathLength);
            $this->object->path = ZipStreamReader::sanitize_path($path);
        }

        if(null === $this->object->extra) {
            $extra = ReaderUtils::read_exactly_n_bytes($this->byte_reader, $this->object->extraLength);
            $this->object->extra = $extra;

            $limit_reader = new LimitProducer(
                $this->byte_reader,
                $this->object->compressedSize
            );

            $is_compressed = $this->object->compressionMethod === ZipStreamReader::COMPRESSION_DEFLATE;
            if($is_compressed) {
                $this->object->body_reader = new InflateProducer($limit_reader, ZLIB_ENCODING_RAW);
            } else {
                $this->object->body_reader = $limit_reader;
            }
            $this->state = self::STATE_OBJECT_READY;
        }
    }

	private function read_central_directory_entry() {
		if (!$this->object) {
			$data = ReaderUtils::read_exactly_n_bytes($this->byte_reader, CentralDirectoryEntry::HEADER_SIZE);
			$header_fields = unpack(
				'vversionCreated/vversionNeeded/vgeneralPurpose/vcompressionMethod/vlastModifiedTime/vlastModifiedDate/Vcrc/VcompressedSize/VuncompressedSize/vpathLength/vextraLength/vfileCommentLength/vdiskNumber/vinternalAttributes/VexternalAttributes/VfirstByteAt',
				$data
			);
			$this->object = new CentralDirectoryEntry($header_fields);
		}

		if(null === $this->object->path) {
            $path_bytes = ReaderUtils::read_exactly_n_bytes($this->byte_reader, $this->object->pathLength);
			$this->object->path = self::sanitize_path($path_bytes);
        }

        if(null === $this->object->extra) {
			$extra_bytes = ReaderUtils::read_exactly_n_bytes($this->byte_reader, $this->object->extraLength);
			$this->object->extra = $extra_bytes;
        }

        if(null === $this->object->fileComment) {
			$file_comment_bytes = ReaderUtils::read_exactly_n_bytes($this->byte_reader, $this->object->fileCommentLength);
			$this->object->fileComment = $file_comment_bytes;
            $this->state = self::STATE_OBJECT_READY;
        }
	}

	private function read_end_central_directory_entry() {
		if (!$this->object) {
			$data = ReaderUtils::read_exactly_n_bytes($this->byte_reader, EndCentralDirectoryEntry::HEADER_SIZE);
			$header_fields = unpack(
				'vdiskNumber/vcentralDirectoryStartDisk/vnumberCentralDirectoryRecordsOnThisDisk/vnumberCentralDirectoryRecords/VcentralDirectorySize/VcentralDirectoryOffset/vcommentLength',
				$data
			);
			$this->object = new EndCentralDirectoryEntry(
                $header_fields
			);
		}

		if (null === $this->object->comment) {
			$comment_bytes = ReaderUtils::read_exactly_n_bytes($this->byte_reader, $this->object->commentLength);
			$this->object->comment = $comment_bytes;
            $this->state = self::STATE_OBJECT_READY;
        }
	}

	private function after_record() {
        if( $this->object instanceof FileEntry) {
            // Skip past the file bytes
            ReaderUtils::read_all_remaining_bytes($this->object->body_reader);
            $this->object->body_reader->close();
        }
		$this->state = self::STATE_SCAN;
		$this->object = null;
	}

	/**
	 * Normalizes the parsed path to prevent directory traversal,
	 * a.k.a zip slip attacks.
	 *
	 * In ZIP, paths are arbitrary byte sequences. Nothing prevents
	 * a ZIP file from containing a path such as /etc/passwd or
	 * ../../../../etc/passwd.
	 *
	 * This function normalizes paths found in the ZIP file.
	 *
	 * @TODO: Scrutinize the implementation of this function. Consider
	 *        unicode characters in the path, including ones that are
	 *        just embelishments of the following character. Consider
	 *        the impact of **all** seemingly "invalid" byte sequences,
	 *        e.g. spaces, ASCII control characters, etc. What will the
	 *        OS do when it receives a path containing .{null byte}./etc/passwd?
	 */
	static public function sanitize_path($path) {
		// Replace multiple slashes with a single slash.
		$path = preg_replace('#/+#', '/', $path);
		// Remove all the leading ../ segments.
		$path = preg_replace('#^(\.\./)+#', '', $path);
		// Remove all the /./ and /../ segments.
		$path = preg_replace('#/\.\.?/#', '/', $path);
		return $path;
	}

}

