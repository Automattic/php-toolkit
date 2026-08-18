<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $post_type ) {
		return in_array( $post_type, array( 'post', 'page' ), true );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return isset( $GLOBALS['mock_options'][ $option ] ) ? $GLOBALS['mock_options'][ $option ] : $default;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return false !== strpos( $email, '@' );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		return isset( $GLOBALS['mock_wp_transients'][ $transient ] ) ? $GLOBALS['mock_wp_transients'][ $transient ] : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		unset( $expiration );
		$GLOBALS['mock_wp_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		unset( $GLOBALS['mock_wp_transients'][ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'init_mock_users' ) ) {
	function init_mock_users() {
		$admin                = new stdClass();
		$admin->ID            = 1;
		$admin->user_login    = 'admin';
		$admin->display_name  = 'Admin User';
		$admin->first_name    = 'Admin';
		$admin->last_name     = 'User';
		$admin->user_email    = 'admin@example.com';
		$admin->user_nicename = 'admin';
		$admin->roles         = array( 'administrator' );
		$admin->description   = 'Administrator Bio';

		$author                = new stdClass();
		$author->ID            = 2;
		$author->user_login    = 'author_jane';
		$author->display_name  = 'Author Jane';
		$author->first_name    = 'Jane';
		$author->last_name     = 'Doe';
		$author->user_email    = 'jane@example.com';
		$author->user_nicename = 'author_jane';
		$author->roles         = array( 'author' );
		$author->description   = 'Jane Bio';

		$sub                = new stdClass();
		$sub->ID            = 3;
		$sub->user_login    = 'subscriber_bob';
		$sub->display_name  = 'Subscriber Bob';
		$sub->first_name    = 'Bob';
		$sub->last_name     = 'Smith';
		$sub->user_email    = 'bob@example.com';
		$sub->user_nicename = 'subscriber_bob';
		$sub->roles         = array( 'subscriber' );
		$sub->description   = 'Subscriber Bio';

		$GLOBALS['mock_users']             = array( 1 => $admin, 2 => $author, 3 => $sub );
		$GLOBALS['mock_user_meta']         = array(
			1 => array(
				'first_name'  => 'Admin',
				'last_name'   => 'User',
				'description' => 'Administrator Bio',
			),
			2 => array(
				'first_name'  => 'Jane',
				'last_name'   => 'Doe',
				'description' => 'Jane Bio',
			),
			3 => array(
				'first_name'  => 'Bob',
				'last_name'   => 'Smith',
				'description' => 'Subscriber Bio',
			),
		);
		$GLOBALS['mock_cannot_edit_users'] = false;
		$GLOBALS['mock_current_user_id']   = 1;
	}
}

if ( ! function_exists( 'init_mock_terms' ) ) {
	function init_mock_terms() {
		$c1              = new stdClass();
		$c1->term_id     = 10;
		$c1->name        = 'News';
		$c1->slug        = 'news';
		$c1->description = 'News category';
		$c1->parent      = 0;

		$c2              = new stdClass();
		$c2->term_id     = 11;
		$c2->name        = 'Tech';
		$c2->slug        = 'tech';
		$c2->description = 'Tech category';
		$c2->parent      = 10;

		$GLOBALS['mock_categories'] = array( 10 => $c1, 11 => $c2 );

		$t1              = new stdClass();
		$t1->term_id     = 20;
		$t1->name        = 'WordPress';
		$t1->slug        = 'wordpress';
		$t1->description = 'WordPress tag';

		$GLOBALS['mock_tags']       = array( 20 => $t1 );
		$GLOBALS['mock_post_terms'] = array();
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return isset( $GLOBALS['mock_current_user_id'] ) ? intval( $GLOBALS['mock_current_user_id'] ) : 1;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, $arg = null ) {
		unset( $arg );
		if ( ! empty( $GLOBALS['mock_cannot_edit_users'] ) ) {
			if ( 'edit_user' === $capability || 'edit_users' === $capability || 'manage_categories' === $capability ) {
				return false;
			}
		}

		return true;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user, $capability ) {
		unset( $capability );
		$user_id = is_object( $user ) ? $user->ID : intval( $user );
		$u       = get_userdata( $user_id );
		if ( ! $u ) {
			return false;
		}
		if ( isset( $u->roles ) && in_array( 'subscriber', $u->roles, true ) ) {
			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $post_type ) {
		$obj                  = new stdClass();
		$obj->name            = $post_type;
		$obj->cap             = new stdClass();
		$obj->cap->edit_posts = 'page' === $post_type ? 'edit_pages' : 'edit_posts';

		return $obj;
	}
}

if ( ! function_exists( 'get_taxonomy' ) ) {
	function get_taxonomy( $taxonomy ) {
		$obj                      = new stdClass();
		$obj->name                = $taxonomy;
		$obj->cap                 = new stdClass();
		$obj->cap->manage_terms   = 'manage_categories';
		$obj->cap->edit_terms     = 'manage_categories';
		$obj->cap->delete_terms   = 'manage_categories';
		$obj->cap->assign_terms   = 'edit_posts';
		$obj->hierarchical        = 'category' === $taxonomy;

		return $obj;
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		return in_array( $taxonomy, array( 'category', 'post_tag' ), true );
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) {
		$users = isset( $GLOBALS['mock_users'] ) && is_array( $GLOBALS['mock_users'] ) ? $GLOBALS['mock_users'] : array();
		foreach ( $users as $u ) {
			if ( 'login' === $field && $u->user_login === $value ) {
				return $u;
			}
			if ( 'email' === $field && $u->user_email === $value ) {
				return $u;
			}
			if ( 'id' === $field && intval( $u->ID ) === intval( $value ) ) {
				return $u;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return get_user_by( 'id', $user_id );
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( $args = array() ) {
		unset( $args );
		return isset( $GLOBALS['mock_users'] ) && is_array( $GLOBALS['mock_users'] ) ? array_values( $GLOBALS['mock_users'] ) : array();
	}
}

if ( ! function_exists( 'wp_update_user' ) ) {
	function wp_update_user( $userdata ) {
		$id = is_object( $userdata ) ? $userdata->ID : ( isset( $userdata['ID'] ) ? $userdata['ID'] : 0 );
		if ( ! isset( $GLOBALS['mock_users'][ $id ] ) ) {
			return new WP_Error();
		}
		$u = $GLOBALS['mock_users'][ $id ];
		foreach ( (array) $userdata as $k => $v ) {
			$u->$k = $v;
			if ( in_array( $k, array( 'first_name', 'last_name', 'description' ), true ) ) {
				update_user_meta( $id, $k, $v );
			}
		}

		return $id;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key = '', $single = false ) {
		$val = isset( $GLOBALS['mock_user_meta'][ $user_id ][ $key ] ) ? $GLOBALS['mock_user_meta'][ $user_id ][ $key ] : '';
		if ( ! $single && '' !== $val ) {
			return array( $val );
		}

		return $val;
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) {
		if ( ! isset( $GLOBALS['mock_user_meta'] ) ) {
			$GLOBALS['mock_user_meta'] = array();
		}
		if ( ! isset( $GLOBALS['mock_user_meta'][ $user_id ] ) ) {
			$GLOBALS['mock_user_meta'][ $user_id ] = array();
		}
		$GLOBALS['mock_user_meta'][ $user_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		if ( 'category' === $taxonomy || ( '' === $taxonomy && isset( $GLOBALS['mock_categories'][ $term_id ] ) ) ) {
			return isset( $GLOBALS['mock_categories'][ $term_id ] ) ? $GLOBALS['mock_categories'][ $term_id ] : false;
		}
		if ( 'post_tag' === $taxonomy || ( '' === $taxonomy && isset( $GLOBALS['mock_tags'][ $term_id ] ) ) ) {
			return isset( $GLOBALS['mock_tags'][ $term_id ] ) ? $GLOBALS['mock_tags'][ $term_id ] : false;
		}

		return false;
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args = array() ) {
		$taxonomy = isset( $args['taxonomy'] ) ? $args['taxonomy'] : 'category';
		if ( 'category' === $taxonomy ) {
			return array_values( isset( $GLOBALS['mock_categories'] ) ? $GLOBALS['mock_categories'] : array() );
		}
		if ( 'post_tag' === $taxonomy ) {
			return array_values( isset( $GLOBALS['mock_tags'] ) ? $GLOBALS['mock_tags'] : array() );
		}

		return array();
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy = '' ) {
		$terms = get_terms( array( 'taxonomy' => $taxonomy ) );
		foreach ( $terms as $t ) {
			if ( 'slug' === $field && $t->slug === $value ) {
				return $t;
			}
			if ( 'name' === $field && $t->name === $value ) {
				return $t;
			}
			if ( 'id' === $field && intval( $t->term_id ) === intval( $value ) ) {
				return $t;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( $name, $taxonomy, $args = array() ) {
		$term_id = 100 + rand( 1, 900 );
		$t       = new stdClass();
		$t->term_id     = $term_id;
		$t->name        = $name;
		$t->slug        = isset( $args['slug'] ) ? $args['slug'] : sanitize_title( $name );
		$t->description = isset( $args['description'] ) ? $args['description'] : '';
		$t->parent      = isset( $args['parent'] ) ? intval( $args['parent'] ) : 0;

		if ( 'category' === $taxonomy ) {
			$GLOBALS['mock_categories'][ $term_id ] = $t;
		} else {
			$GLOBALS['mock_tags'][ $term_id ] = $t;
		}

		return array( 'term_id' => $term_id );
	}
}

if ( ! function_exists( 'wp_update_term' ) ) {
	function wp_update_term( $term_id, $taxonomy, $args = array() ) {
		$t = get_term( $term_id, $taxonomy );
		if ( ! $t ) {
			return new WP_Error();
		}
		if ( isset( $args['name'] ) ) {
			$t->name = $args['name'];
		}
		if ( isset( $args['slug'] ) ) {
			$t->slug = $args['slug'];
		}
		if ( isset( $args['description'] ) ) {
			$t->description = $args['description'];
		}
		if ( isset( $args['parent'] ) ) {
			$t->parent = intval( $args['parent'] );
		}

		return array( 'term_id' => $term_id );
	}
}

if ( ! function_exists( 'wp_delete_term' ) ) {
	function wp_delete_term( $term_id, $taxonomy ) {
		if ( 'category' === $taxonomy ) {
			unset( $GLOBALS['mock_categories'][ $term_id ] );
		} else {
			unset( $GLOBALS['mock_tags'][ $term_id ] );
		}

		return true;
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( $object_id, $terms, $taxonomy ) {
		if ( ! isset( $GLOBALS['mock_post_terms'][ $object_id ] ) ) {
			$GLOBALS['mock_post_terms'][ $object_id ] = array();
		}
		$GLOBALS['mock_post_terms'][ $object_id ][ $taxonomy ] = (array) $terms;
		return (array) $terms;
	}
}

if ( ! function_exists( 'wp_set_post_tags' ) ) {
	function wp_set_post_tags( $post_id, $tags = array(), $append = false ) {
		unset( $append );
		if ( ! isset( $GLOBALS['mock_post_terms'][ $post_id ] ) ) {
			$GLOBALS['mock_post_terms'][ $post_id ] = array();
		}
		$GLOBALS['mock_post_terms'][ $post_id ]['post_tag'] = (array) $tags;
		return true;
	}
}

if ( ! function_exists( 'get_ancestors' ) ) {
	function get_ancestors( $object_id, $object_type = '', $resource_type = '' ) {
		unset( $object_type, $resource_type );
		$ancestors = array();
		$curr      = get_term( $object_id, 'category' );
		while ( $curr && ! empty( $curr->parent ) ) {
			$ancestors[] = $curr->parent;
			$curr        = get_term( $curr->parent, 'category' );
		}

		return $ancestors;
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field ) {
		$output = array();
		foreach ( $list as $item ) {
			if ( is_object( $item ) && isset( $item->$field ) ) {
				$output[] = $item->$field;
			}
		}

		return $output;
	}
}

require_once dirname( __DIR__ ) . '/class-push-md-seeder.php';
require_once dirname( __DIR__ ) . '/class-push-md-admin.php';
require_once dirname( __DIR__ ) . '/class-push-md-pull-requests.php';
require_once dirname( __DIR__ ) . '/class-push-md-master-metadata.php';
require_once dirname( __DIR__ ) . '/class-push-md-plugin.php';

class MasterMetadataTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wp_filter']      = array();
		$GLOBALS['mock_post_meta'] = array();
		init_mock_users();
		init_mock_terms();
		Push_MD_Plugin::bootstrap();
	}

	private function invoke_private( $method, $args = array() ) {
		$target_class = method_exists( Push_MD_Master_Metadata::class, $method ) ? Push_MD_Master_Metadata::class : Push_MD_Plugin::class;
		$reflection   = new ReflectionClass( $target_class );
		$m            = $reflection->getMethod( $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true );
		}

		return $m->invokeArgs( null, $args );
	}

	public function testIsMasterMetadataPathIdentifiesMasterFiles() {
		$this->assertTrue( $this->invoke_private( 'is_master_metadata_path', array( 'categories.md' ) ) );
		$this->assertTrue( $this->invoke_private( 'is_master_metadata_path', array( 'tags.md' ) ) );
		$this->assertTrue( $this->invoke_private( 'is_master_metadata_path', array( 'authors.md' ) ) );
		$this->assertFalse( $this->invoke_private( 'is_master_metadata_path', array( 'post/test.md' ) ) );
	}

	public function testResolveFrontmatterAuthorIdResolvesAdminUser() {
		$author_id = $this->invoke_private( 'resolve_frontmatter_author_id', array( 'admin' ) );
		$this->assertEquals( 1, $author_id );
	}

	public function testResolveCategoryPathToTermIdResolvesNestedCategories() {
		$parent_id = $this->invoke_private( 'resolve_category_path_to_term_id', array( 'News' ) );
		$this->assertEquals( 10, $parent_id );

		$child_id = $this->invoke_private( 'resolve_category_path_to_term_id', array( 'News > Tech' ) );
		$this->assertEquals( 11, $child_id );
	}

	public function testResolveTagNameToTermIdResolvesTag() {
		$tag_id = $this->invoke_private( 'resolve_tag_name_to_term_id', array( 'WordPress' ) );
		$this->assertEquals( 20, $tag_id );
	}

	public function testParseFrontmatterListParsesCommaSeparatedAndJsonArrays() {
		$parsed_comma = $this->invoke_private( 'parse_frontmatter_list', array( 'News > Tech, Reviews' ) );
		$this->assertEquals( array( 'News > Tech', 'Reviews' ), $parsed_comma );

		$parsed_json = $this->invoke_private( 'parse_frontmatter_list', array( '["News > Tech", "Reviews"]' ) );
		$this->assertEquals( array( 'News > Tech', 'Reviews' ), $parsed_json );
	}

	public function testValidatePostFrontmatterReferencesRejectsUnknownCategory() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'category "UnknownCategory" is incorrect or was not found' );

		$metadata = array(
			'categories' => 'UnknownCategory',
		);
		$this->invoke_private( 'validate_post_frontmatter_references', array( $metadata, 'post', array(), 'post/my-post.md' ) );
	}

	public function testValidatePostFrontmatterReferencesRejectsUnknownAuthor() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'author "unknown_user" is incorrect or was not found' );

		$metadata = array(
			'author' => 'unknown_user',
		);
		$this->invoke_private( 'validate_post_frontmatter_references', array( $metadata, 'post', array(), 'post/my-post.md' ) );
	}

	public function testValidatePostFrontmatterReferencesRejectsUnknownTagIncludesFilePathAndReferenceGuide() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Push rejected in file "post/test.md" because tag "NonExistentTag" is incorrect or was not found in tags.md or WordPress. Please refer to tags.md in your local repository for correct values.' );

		$metadata = array(
			'tags' => 'NonExistentTag',
		);
		$this->invoke_private( 'validate_post_frontmatter_references', array( $metadata, 'post', array(), 'post/test.md' ) );
	}

	public function testExportCategoriesMarkdownIncludesParentSlugInYamlList() {
		$markdown = $this->invoke_private( 'export_categories_markdown' );
		$this->assertStringContainsString( 'categories:', $markdown );
		$this->assertStringContainsString( 'id: "10"', $markdown );
		$this->assertStringContainsString( 'slug: "tech"', $markdown );
		$this->assertStringContainsString( 'parent: "news"', $markdown );
	}

	public function testExportAuthorsMarkdownIncludesUserFieldsInYamlListAndExcludesSubscribers() {
		$markdown = $this->invoke_private( 'export_authors_markdown' );
		$this->assertStringContainsString( 'authors:', $markdown );
		$this->assertStringContainsString( '- user_login: "admin"', $markdown );
		$this->assertStringContainsString( 'display_name: "Admin User"', $markdown );
		$this->assertStringContainsString( 'first_name: "Admin"', $markdown );
		$this->assertStringContainsString( 'last_name: "User"', $markdown );
		$this->assertStringContainsString( '- user_login: "author_jane"', $markdown );
		$this->assertStringNotContainsString( 'subscriber_bob', $markdown );
		$this->assertStringNotContainsString( 'roles', $markdown );
	}

	public function testSyncAuthorsFromMarkdownUpdatesUserDataAndFirstNameLastNameInYamlList() {
		$authors_md = <<<MD
---
authors:
  - user_login: "admin"
    display_name: "Jane Doe"
    first_name: "Jane"
    last_name: "Doe"
    user_email: "jane@example.com"
    user_nicename: "admin"
    description: "Updated Bio Text"
---

# WordPress Authors Master Reference
MD;

		$this->invoke_private( 'sync_authors_from_markdown', array( $authors_md ) );

		$user = get_userdata( 1 );
		$this->assertEquals( 'Jane Doe', $user->display_name );
		$this->assertEquals( 'Jane', $user->first_name );
		$this->assertEquals( 'Doe', $user->last_name );
		$this->assertEquals( 'jane@example.com', $user->user_email );
		$this->assertEquals( 'Updated Bio Text', $user->description );

		$exported = $this->invoke_private( 'export_authors_markdown' );
		$this->assertStringContainsString( 'display_name: "Jane Doe"', $exported );
		$this->assertStringContainsString( 'first_name: "Jane"', $exported );
		$this->assertStringContainsString( 'last_name: "Doe"', $exported );
		$this->assertStringContainsString( 'user_email: "jane@example.com"', $exported );
		$this->assertStringContainsString( 'description: "Updated Bio Text"', $exported );
	}

	public function testAuthorCanUpdateOwnProfileButCannotUpdateOtherAuthor() {
		$GLOBALS['mock_current_user_id']   = 2;
		$GLOBALS['mock_cannot_edit_users'] = true;

		$own_author_md = <<<MD
---
authors:
  - user_login: "author_jane"
    display_name: "Jane Updated Bio"
    first_name: "Jane"
    last_name: "Doe"
    user_email: "jane@example.com"
    user_nicename: "author_jane"
    description: "My Updated Bio"
---

# WordPress Authors Master Reference
MD;

		$this->invoke_private( 'assert_can_sync_authors', array( $own_author_md ) );

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'do not have permission to edit author "admin"' );

		$other_author_md = <<<MD
---
authors:
  - user_login: "admin"
    display_name: "Attempted Admin Edit"
    user_email: "admin@example.com"
    user_nicename: "admin"
    description: "Hacked Bio"
---

# WordPress Authors Master Reference
MD;

		$this->invoke_private( 'assert_can_sync_authors', array( $other_author_md ) );
	}

	public function testSyncCategoriesFromMarkdownAddsUpdatesAndDeletesCategories() {
		$categories_md = <<<MD
---
categories:
  - id: "10"
    name: "News Updated"
    slug: "news"
    description: "Updated News Desc"
    parent: ""
  - name: "Sports"
    slug: "sports"
    description: "Sports category"
    parent: "news"
---

# WordPress Categories Master Reference
MD;

		$this->invoke_private( 'sync_categories_from_markdown', array( $categories_md ) );

		$news = get_term( 10, 'category' );
		$this->assertNotNull( $news );
		$this->assertEquals( 'News Updated', $news->name );
		$this->assertEquals( 'Updated News Desc', $news->description );

		$tech = get_term( 11, 'category' );
		$this->assertFalse( $tech );

		$sports = get_term_by( 'slug', 'sports', 'category' );
		$this->assertNotNull( $sports );
		$this->assertEquals( 'Sports category', $sports->description );

		$exported = $this->invoke_private( 'export_categories_markdown' );
		$this->assertStringContainsString( 'name: "News Updated"', $exported );
		$this->assertStringContainsString( 'slug: "sports"', $exported );
		$this->assertStringNotContainsString( 'slug: "tech"', $exported );
	}

	public function testSyncTagsFromMarkdownAddsUpdatesAndDeletesTags() {
		$tags_md = <<<MD
---
tags:
  - id: "20"
    name: "WordPress Pro"
    slug: "wordpress"
    description: "Updated WP Tag"
  - name: "PHP 8"
    slug: "php-8"
    description: "PHP 8 programming"
---

# WordPress Tags Master Reference
MD;

		$this->invoke_private( 'sync_tags_from_markdown', array( $tags_md ) );

		$wp_tag = get_term( 20, 'post_tag' );
		$this->assertNotNull( $wp_tag );
		$this->assertEquals( 'WordPress Pro', $wp_tag->name );
		$this->assertEquals( 'Updated WP Tag', $wp_tag->description );

		$php_tag = get_term_by( 'slug', 'php-8', 'post_tag' );
		$this->assertNotNull( $php_tag );
		$this->assertEquals( 'PHP 8 programming', $php_tag->description );

		$exported = $this->invoke_private( 'export_tags_markdown' );
		$this->assertStringContainsString( 'name: "WordPress Pro"', $exported );
		$this->assertStringContainsString( 'slug: "php-8"', $exported );
	}

	public function testAssignPostCategoriesAndTagsUpdatesPostTerms() {
		$this->invoke_private( 'assign_post_categories', array( 100, 'News > Tech' ) );
		$this->assertEquals( array( 11 ), $GLOBALS['mock_post_terms'][100]['category'] );

		$this->invoke_private( 'assign_post_tags', array( 100, 'WordPress' ) );
		$this->assertEquals( array( 'WordPress' ), $GLOBALS['mock_post_terms'][100]['post_tag'] );

		$this->invoke_private( 'assign_post_categories', array( 100, '' ) );
		$this->assertEquals( array(), $GLOBALS['mock_post_terms'][100]['category'] );
	}
}
