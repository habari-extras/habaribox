<?php

class HabariBox extends Plugin
{
	/**
	 * Check that we've initiated all posts before doing anything else 
	 **/
	private function is_initiated()
	{
		$yes = true;
		
		if( Options::get('habaribox__initiated') == (null || false ) )
		{
			$yes = false;
		}
						
		return $yes;
	}
	
	/**
	 * gets the name of the directory to store posts in 
	 **/
	private function get_storage_directory()
	{
		return Utils::slugify( Options::get('title') );
	}
	
	public function action_init()
	{
		if( !$this->is_initiated() )
		{
			$this->check_posts();
		}
	}
	
	public function action_plugin_activation( $file )
	{
		if ( $file == str_replace( '\\','/', $this->get_file() ) ) {
			$this->check_posts(); // this will likely take some time, should possibly be queued
		}
	}
	
	/**
	 * Update the Dropbox copy when contents are changed in Habari 
	 **/
	public function action_post_update_content( $post, $original_content, $new_content )
	{
		if( !$this->create_api() )
		{
			return;
		}
		
		// Utils::debug( $post->slug, $original_content, $new_content );
				
		$this->api->update_file( $post->slug, $new_content );

	}
	
	/**
	 * Expire the cache when you go to edit a specific post
	 **/
	public function action_form_publish( $form, $post, $context )
	{
		if( !$this->create_api() || $post->id == 0 )
		{
			return;
		}
				
		Cache::expire( array('habaribox', $post->slug ) );
		
		// $form->content->value = $this->api->get_file_contents( $post->slug );
	}
	
	/**
	 * Update the Habari post with the Dropbox contents 
	 **/
	public function filter_post_content( $content, $post )
	{
		if( !$this->create_api() || $post->id == 0 )
		{
			return $content;
		}
		
		// Utils::debug( $post->id );
		
		if( Cache::has( array('habaribox', $post->slug ) ) )
		{
			$file_content = Cache::get( array('habaribox', $post->slug ) );
		}
		else
		{
			$file_content = $this->api->get_file_contents( $post->slug );
			Cache::set( array('habaribox', $post->slug ), $file_content );
		}
		
		if( $content != $file_content )
		{			
			$content = $file_content;
			$post->content = $file_content;
			$post->update();
		}

		return $content;
	}
	
	/**
	 * Checks all posts for their DropBox status and creates/updates the DropBox file if necessary 
	 **/
	private function check_posts()
	{
		$this->create_api();
		
		$posts = Posts::get( array( 'nolimit' => true ) );
		
		// $posts = array( $posts[0] ); // for testing, just use first post
		
		$base_dir_contents = $this->api->get_directory('', false);
		
		if( !isset( $base_dir_contents[ $this->get_storage_directory() ] ) )
		{
			$this->api->create_folder( $this->get_storage_directory() );
		}
				
		foreach( $posts as $post )
		{
			$this->check_post( $post );
		}
		
		Options::set('habaribox__initiated', true);
	}
	
	/**
	 * Checks a posts for its DropBox status and creates/updates the DropBox file if necessary 
	 **/
	private function check_post( $post )
	{
		$this->create_api();
		
		$list = $this->api->get_directory();
		
		// Utils::debug( $list );
		
		if(isset( $list[$post->slug] ) )
		{
			// handle the post if it already exists
			// $this->api->update_file( $post->slug, $post->content );
		}
		else
		{
			// Utils::debug( $post );
			
			$this->api->create_file( $post->slug, $post->content );
			
		}
		
	}
	
	/**
	 * Creates a DropBox API object if one doesn't already exist 
	 **/
	private function create_api()
	{
		if( !isset( $this->api ) )
		{
			$base_dir = $this->get_storage_directory() . '/';
			$sdk_base = dirname( $this->get_file() ) . '/dropbox-library/Dropbox/';
			$key = '91x3fmog7f0dng1';
			$secret = 'nu12ovzjfepjhby';
			$this->api = new DropboxAPI( $sdk_base, $base_dir, $key, $secret);
		}
		
		return $this->is_initiated();
	}
		
}

class DropboxAPI
{
	
	private $key;
	private $secret;
		
	public function __construct( $sdk_base, $directory, $key, $secret )
	{
		$this->base_dir = $directory;
		$this->sdk_base = $sdk_base;
		$this->key = $key;
		$this->secret = $secret;
		
		// Check whether to use HTTPS and set the callback URL
		$this->protocol = (!empty($_SERVER['HTTPS'])) ? 'https' : 'http';
		$this->callback = $this->protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		
		// Register a simple autoload function
		// spl_autoload_register(function($class){
		// 	$class = str_replace('\\', '/', $class);
		// 	require_once('../' . $class . '.php');
		// });
		
		// Include required SDK files
		require_once( $this->sdk_base . '/OAuth/Storage/StorageInterface.php');
		require_once( $this->sdk_base . '/OAuth/Storage/Encrypter.php');
		require_once( $this->sdk_base . '/OAuth/Storage/Session.php');
		require_once( $this->sdk_base . '/OAuth/Consumer/ConsumerAbstract.php');
		require_once( $this->sdk_base . '/OAuth/Consumer/Curl.php');
		require_once( $this->sdk_base . '/Exception.php');
		require_once( $this->sdk_base . '/API.php');
		
		// Instantiate the required Dropbox objects
		$this->encrypter = new Dropbox\OAuth\Storage\Encrypter('XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');
		$this->storage = new Dropbox\OAuth\Storage\Session($this->encrypter);
		$this->OAuth = new Dropbox\OAuth\Consumer\Curl($this->key, $this->secret, $this->storage, $this->callback);
		$this->dropbox = new Dropbox\API($this->OAuth);
	}
	
	public function get_account_info()
	{
		// Retrieve the account information
		return $this->dropbox->accountInfo();
	}
	
	public function get_directory( $path = '', $base_dir = true )
	{
		if( $base_dir == true )
		{
			$path = $this->base_dir . $path;
		}
				
		$data = $this->get_metadata( $path );
		
		$contents = $data['body']->contents;
		
		$list = array();
		
		foreach( $contents as $content )
		{
			$list[ Utils::slugify($content->path) ] = $content;
		}
			
		return $list;
	}
	
	public function get_metadata( $path = '')
	{
		$path = $path;
		
		$data = $this->dropbox->metaData($path);
				
		return $data;
		
	}
	
	public function get_file( $name, $extension = 'html', $path = '' )
	{
		$data = $this->dropbox->getFile( $this->base_dir . $path . $name . '.' . $extension);
		
		// Utils::debug( $data );
		
		return $data;
		
	}
	
	public function get_file_contents( $name, $extension = 'html', $path = '' )
	{
		$file = $this->get_file( $name, $extension, $path );
		
		$data = $file['data'];
		
		return $data;
	}
	
	public function update_file( $name, $contents, $extension = 'html', $path = '' )
	{
		return $this->create_file( $name, $contents, $extension, $path );	
	}
	
	public function create_file( $name, $contents, $extension = 'html', $path = '' )
	{
		// create a new file for the post
		$tmp = tempnam('/tmp', 'dropbox');
		$data = $contents;
		// Utils::debug( $data, $contents );
		
		file_put_contents($tmp, $data);
		
		// Utils::debug( $this->base_dir . $path . $name . '.' . $extension );
		
		// Utils::debug( $this->base_dir . $path . $name . '.' . $extension );
		
		// Upload the file with an alternative filename
		$put = $this->dropbox->putFile($tmp, $name . '.' . $extension, $this->base_dir . $path);

		// Unlink the temporary file
		unlink($tmp);
		
	}
	
	public function create_folder( $path )
	{
		$this->dropbox->create( $path );
	}
	
}

?>