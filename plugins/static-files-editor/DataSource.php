<?php

use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRemote;
use WordPress\Git\GitRepository;

interface DataSource {
    public function refresh_index();
    public function get_filesystem(): Filesystem;
}

class GitDataSource implements DataSource {

    private $remote;
    private $git_repository;
    private $git_filesystem;
    private $full_branch_name;
    private $subdirectory;

    static public function create( $config ) {
        $dot_git_path = $config['.gitPath'] ?? WP_STATIC_PAGES_DIR;
        if ( ! is_dir( $dot_git_path ) ) {
            mkdir( $dot_git_path, 0777, true );
        }

        $local_fs = LocalFilesystem::create( $dot_git_path );
        
        $repo     = new GitRepository( $local_fs );
        $repo->add_remote( 'origin', $config['gitRepo'] );
        $repo->set_branch_tip( 'HEAD', 'ref: refs/heads/' . $config['selectedBranch'] );
        $repo->set_config_value( 'user.name', $config['gitUserName'] );
        $repo->set_config_value( 'user.email', $config['gitUserEmail'] );

        return new GitDataSource( $repo, [
            'remoteName' => $config['remoteName'] ?? 'origin',
            'subdirectory' => $config['subdirectory'] ?? '',
            'selectedBranch' => $config['selectedBranch'] ?? '',
        ] );
    }

    public function __construct( $git_repository, $config ) {
        $this->subdirectory = $config['subdirectory'] ?? '';
        $this->full_branch_name = $config['selectedBranch'] ?? '';
        $this->remote = new GitRemote( $git_repository, $config['remoteName'] );
        $this->git_repository = $git_repository;
        $this->git_filesystem = GitFilesystem::create(
            $this->git_repository,
            [
                'root'      => $this->subdirectory,
                'auto_push' => true,
                'remote'    => $this->remote,
            ]
        );
    }

    public function refresh_index() {
        $this->remote->pull( $this->full_branch_name, [
            'path' => $this->subdirectory,
        ] );
    }

    public function get_filesystem(): Filesystem {
        return $this->git_filesystem;
    }
}

class LocalDirectoryDataSource implements DataSource {

    private $local_filesystem;

    public function __construct( $local_filesystem ) {
        $this->local_filesystem = $local_filesystem;
    }

    public function refresh_index() {
        // No op
    }

    public function get_filesystem(): Filesystem {
        return $this->local_filesystem;
    }

}
