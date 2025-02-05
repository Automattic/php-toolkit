<?php

namespace WordPress\Git;

use WordPress\ByteStream\MemoryPipe;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\TreeEntry;
use WordPress\Git\Protocol\GitProtocolEncoderPipe;
use WordPress\Git\Protocol\Parser\GitProtocolDecoder;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;


class GitRemote {
	/**
	 * @var Client
	 */
	private $http_client;
	/**
	 * @var GitRepository
	 */
	private $repository;
	private $remote_name;

	public function __construct( GitRepository $repository, $remote_name, $options = array() ) {
		$this->remote_name = $remote_name;
		$this->repository  = $repository;
		$this->http_client = $options['http_client'] ?? new Client(
			array(
				'timeout' => 300,
			)
		);
	}

	public function ls_refs( $prefix = '' ) {
		$response = $this->http_request(
			'/git-upload-pack',
			GitProtocolEncoderPipe::encode_packet_lines(
				array(
					"command=ls-refs\n",
					"agent=git/2.37.3\n",
					"object-format=sha1\n",
					'0001',
					"peel\n",
					"ref-prefix $prefix\n",
					'0000',
				)
			),
			array(
				'Accept'       => 'application/x-git-upload-pack-advertisement',
				'Content-Type' => 'application/x-git-upload-pack-request',
				'Git-Protocol' => 'version=2',
			)
		);

		$refs     = array();
		$protocol = new GitProtocolDecoder( $response, array( 'will_process_pack' => false ) );
		while ( $protocol->next_token() ) {
			switch ( $protocol->get_token_type() ) {
				case '#packet-footer':
					$ref_line = $protocol->get_packet_body();
					$ref      = $this->parse_ref_line( $ref_line );
					if ( false === $ref ) {
						continue 2;
					}
					$refs[ $ref['ref_name'] ] = $ref['hash'];

					if ( str_starts_with( $ref['ref_name'], 'refs/heads/' ) ) {
						$branch_name = substr( $ref['ref_name'], strlen( 'refs/heads/' ) );
						$this->repository->set_ref_head( 'refs/remotes/' . $this->remote_name . '/' . $branch_name, $ref['hash'] );
					}
					break;
			}
		}
		return $refs;
	}

	private function parse_ref_line( $ref_line ) {
		$space_pos = strpos( $ref_line, ' ' );
		if ( $space_pos === false ) {
			return false;
		}
		$hash     = substr( $ref_line, 0, $space_pos );
		$ref_name = substr( $ref_line, $space_pos + 1 );

		// Check for peeled hash at end
		if ( preg_match( '/^(.+) peeled:([a-f0-9]{40})$/', $ref_name, $matches ) ) {
			$ref_name = $matches[1];
			$hash     = $matches[2];
		}

		return array(
			'hash' => $hash,
			'ref_name' => $ref_name,
		);
	}

	public function force_push_one_commit() {
		$push_ref_name = $this->repository->get_ref_head( 'HEAD', array( 'follow_symrefs' => false ) );
		$push_ref_name = $this->localize_ref_name( $push_ref_name );

		$push_commit = $this->repository->get_ref_head( 'refs/heads/' . $push_ref_name );
		$parent_hash = $this->repository->read_object( $push_commit )->as_commit()->get_first_parent_hash();

		$remote_commit = $this->repository->get_ref_head( 'refs/remotes/' . $this->remote_name . '/' . $push_ref_name );
		// @TODO: Do find_objects_added_since to enable pushing multiple commits at once.
		// OR! perhaps supporting "have" and "want" would solve this.
		// $delta = $this->repository->find_objects_added_in($push_commit, $parent_hash);
		$delta = $this->repository->find_objects_added_in( $push_commit, $remote_commit );

		if ( ! count( $delta ) ) {
			// Don't push empty commits
			return;
		}

		$producer = new GitProtocolEncoderPipe();
		$producer->append_packet_line( "$remote_commit $push_commit refs/heads/$push_ref_name\0report-status force-update side-band-64k\n" );
		$producer->append_packet_line( '0000' );
		$producer->append_packfile( $this->repository, $delta );
		$producer->close_writing();

		$response = $this->http_request(
			'/git-receive-pack',
			$producer,
			array(
				'Content-Type' => 'application/x-git-receive-pack-request',
				'Accept'       => 'application/x-git-receive-pack-result',
			)
		);

		$data_packets = array();
		$protocol     = new GitProtocolDecoder(
			$response,
			array( 'will_process_pack' => false )
		);
		while ( $protocol->next_token() ) {
			switch ( $protocol->get_token_type() ) {
				case '#packet-footer':
					$data_packets[] = $protocol->get_packet_body();
					break;
			}
		}

		$expected_response = array(
			'unpack ok',
			'ok refs/heads/' . $push_ref_name,
		);
		if ( $data_packets != $expected_response ) {
			throw new GitException( 'Push failed:' . var_export( $data_packets, true ) );
		}

		$this->repository->set_ref_head( 'refs/remotes/' . $this->remote_name . '/' . $push_ref_name, $push_commit );
	}

	private function localize_ref_name( $ref_name ) {
		if ( str_starts_with( $ref_name, 'ref: ' ) ) {
			$ref_name = trim( substr( $ref_name, 5 ) );
		}
		if ( str_starts_with( $ref_name, 'refs/heads/' ) ) {
			return substr( $ref_name, strlen( 'refs/heads/' ) );
		}

		return $ref_name;
	}

	public function list_objects( $ref_hash, ): GitRepository {
		$response = $this->request_objects_list( $ref_hash );
		$tmp_repo = new GitRepository( InMemoryFilesystem::create() );
		$tmp_repo->set_ref_head( 'HEAD', $ref_hash );
		$protocol = new GitProtocolDecoder(
			$response,
			array(
				'write_to_repository' => $tmp_repo,
				'resolve_deltas_from_repository' => $this->repository,
			)
		);
		$protocol->consume_stream();
		return $tmp_repo;
	}

	private function request_objects_list( $ref_hash ) {
		return $this->http_request(
			'/git-upload-pack',
			GitProtocolEncoderPipe::encode_packet_lines(
				array(
					"want {$ref_hash} multi_ack_detailed no-done side-band thin-pack ofs-delta agent=git/2.37.3 filter\n",
					"filter blob:none\n",
					"shallow {$ref_hash}\n",
					"deepen 1\n",
					'0000',
					"done\n",
					"done\n",
				)
			),
		);
	}

	public function fetch_branch( $options = array() ) {
		$branch_name = $this->resolve_branch_name( $options['branch'] ?? null );
		try {
			$last_fetched_head_ref = $this->repository->get_ref_head( 'refs/remotes/' . $this->remote_name . '/' . $branch_name );
		} catch ( GitException $e ) {
			$last_fetched_head_ref = Commit::NULL_HASH;
		}

		$remote_refs = $this->ls_refs( 'refs/heads/' . $branch_name );
		$key         = 'refs/heads/' . $branch_name;
		if ( ! isset( $remote_refs[ $key ] ) ) {
			throw new GitException( 'Branch "' . $branch_name . '" not found on remote ' . $this->remote_name );
		}
		$remote_head = $remote_refs[ $key ];

		$shallow   = $options['shallow'] ?? false;
		$want_oids = array();
		$have_oids = array();
		if ( isset( $options['path'] ) ) {
			if ( ! $shallow ) {
				throw new GitException( 'When the "path" option is used, "shallow" option must also be true. Non-shallow path fetch is not supported yet.' );
			}

			$response = $this->request_objects_list( $remote_head );
			$protocol = new GitProtocolDecoder(
				$response,
				array(
					'write_to_repository' => $this->repository,
					'resolve_deltas_from_repository' => $this->repository,
				)
			);
			$protocol->consume_stream();

			$commit                = $this->repository->read_object( $remote_head )->as_commit();
			$subpath               = trim( $options['path'], '/' );
			$requested_tree_oid    = $this->repository->find_hash_by_path( $subpath, $commit->tree );
			$descentant_blobs_oids = get_all_descendant_oids_in_tree(
				$this->repository,
				$requested_tree_oid,
				array(
					'object_types' => array(
						TreeEntry::FILE_MODE_REGULAR_EXECUTABLE,
						TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
					),
				)
			);

			$want_oids[] = $remote_head;
			foreach ( $descentant_blobs_oids as $oid ) {
				if ( ! $this->repository->has_object( $oid ) ) {
					$want_oids[] = $oid;
				}
			}
		} else {
			$want_oids[] = $remote_head;
			$local_head  = $this->repository->get_ref_head( 'HEAD' );
			for ( $i = 0; $i < 10; $i++ ) {
				if ( $local_head === Commit::NULL_HASH ) {
					break;
				}

				try {
					$local_commit = $this->repository->read_object( $local_head )->as_commit();
					$have_oids[]  = $local_commit->hash;
					$local_head   = $local_commit->get_first_parent_hash();
				} catch ( GitException $e ) {
					break;
				}
			}
		}

		if ( count( $want_oids ) === 0 || $want_oids === $have_oids ) {
			return $remote_head;
		}

		$this->fetch_specific_objects( $want_oids, $have_oids, $options );
		$this->repository->set_ref_head( "refs/remotes/{$this->remote_name}/{$branch_name}", $remote_head );
		return $remote_head;
	}

	public function pull( $options = array() ) {
		$options['branch'] = $this->resolve_branch_name( $options['branch'] ?? null );
		// @TODO: Don't run in sparse mode.
		$options['shallow'] = true;
		$options['path']    = '/';
		$remote_head        = $this->fetch_branch( $options );
		return $this->repository->merge( $remote_head );
	}

	public function force_pull( $options = array() ) {
		$options['branch'] = $this->resolve_branch_name( $options['branch'] ?? null );
		$remote_head       = $this->fetch_branch( $options );
		$this->repository->set_ref_head( 'refs/heads/' . $options['branch'], $remote_head );
		return $remote_head;
	}

	private function resolve_branch_name( $branch_name ) {
		if ( null !== $branch_name ) {
			return $branch_name;
		}

		// Return the default branch name
		$branch_name = $this->repository->get_ref_head( 'HEAD', array( 'follow_symrefs' => false ) );
		$branch_name = $this->localize_ref_name( $branch_name );
		return $branch_name;
	}

	public function fetch_specific_objects( $want_refs, $have_refs = array(), $options = array() ) {
		if ( empty( $want_refs ) ) {
			return;
		}
		$packet_lines = array();
		for ( $i = 0; $i < count( $want_refs ); $i++ ) {
			$packet_line = "want {$want_refs[$i]}";
			if ( $i === 0 ) {
				$packet_line .= ' multi_ack_detailed no-done side-band-64k thin-pack ofs-delta agent=git/2.37.3';
			}
			$packet_line   .= "\n";
			$packet_lines[] = $packet_line;
		}
		foreach ( $have_refs as $have_ref ) {
			if ( Commit::is_null_hash( $have_ref ) ) {
				continue;
			}
			$packet_lines[] = "have {$have_ref}\n";
		}
		$shallow = $options['shallow'] ?? false;
		if ( $shallow ) {
			// @TODO: revisit this logic. We can only shallow fetch commits without a fatal error.
			// Not blobs or trees. Define an API to enable an explicit decision here.
			if ( count( $want_refs ) === 1 ) {
				$packet_lines[] = "shallow {$want_refs[0]}\n";
			}
			$packet_lines[] = "deepen 1\n";
		}

		$packet_lines[] = '0000';
		$packet_lines[] = "done\n";
		$packet_lines[] = "done\n";

		$response = $this->http_request(
			'/git-upload-pack',
			GitProtocolEncoderPipe::encode_packet_lines( $packet_lines ),
			array(
				'Accept' => 'application/x-git-upload-pack-advertisement',
				'Content-Type' => 'application/x-git-upload-pack-request',
			)
		);

		$protocol = new GitProtocolDecoder(
			$response,
			array(
				'write_to_repository' => $this->repository,
			)
		);
		$protocol->consume_stream();
	}

	private function http_request( $path, $postData = null, $headers = array() ) {
		$remote = $this->repository->get_remote( $this->remote_name );
		if ( ! $remote ) {
			throw new GitException( 'Remote "' . $this->remote_name . '" not found' );
		}
		$url = $remote['url'] . $path;

		// @TODO: Make it configurable in Playground, maybe via a filter
		$request_info = array(
			'headers' => $headers,
		);
		if ( $postData ) {
			$request_info['method']      = 'POST';
			$request_info['body_stream'] = is_string( $postData ) ? new MemoryPipe( $postData ) : $postData;
		}

		$request = new Request( $url, $request_info );
		$reader  = $this->http_client->fetch( $request );

		$response = $reader->await_response();
		if ( $response->status_code > 299 || $response->status_code < 200 ) {
			throw new GitException( 'HTTP request failed with status code ' . $response->status_code . '. First 100 body bytes: ' . $reader->peek( 100 ) );
		}

		return $reader;
	}
}
