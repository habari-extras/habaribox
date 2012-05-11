<?php

class HabariBox extends Plugin
{
	
	public function action_init()
	{
		// $this->check_posts();
	}
	
	/**
	 * Update the Dropbox copy when contents are changed in Habari 
	 **/
	public function action_post_update_before( $post )
	{
		$this->create_api();
		
		$this->api->update_file( $post->slug, $post->content );

	}
	
	/**
	 * Update the Habari post with the Dropbox contents 
	 **/
	public function filter_post_content( $content, $post )
	{
		$this->create_api();
		
		// we should probably do some caching	
		$file_content = $this->api->get_file_contents( $post->slug );
		
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
		$posts = Posts::get( array( 'nolimit' => true ) );
		
		// $posts = array( $posts[0] ); // for testing, just use first post
		
		foreach( $posts as $post )
		{
			$this->check_post( $post );
		}
	}
	
	/**
	 * Checks a posts for its DropBox status and creates/updates the DropBox file if necessary 
	 **/
	private function check_post( $post )
	{
		$this->create_api();
		
		$list = $this->api->get_directory();
		
		if(isset( $list[$post->slug] ) )
		{
			// handle the post if it already exists
			$this->api->update_file( $post->slug, $post->content );
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
			$base_dir = '';
			$sdk_base = dirname( $this->get_file() ) . '/dropbox-library/Dropbox/';
			$key = '91x3fmog7f0dng1';
			$secret = 'nu12ovzjfepjhby';
			$this->api = new DropboxAPI( $sdk_base, $base_dir, $key, $secret);
		}
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
		$this->encrypter = new \Dropbox\OAuth\Storage\Encrypter('XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');
		$this->storage = new \Dropbox\OAuth\Storage\Session($this->encrypter);
		$this->OAuth = new \Dropbox\OAuth\Consumer\Curl($this->key, $this->secret, $this->storage, $this->callback);
		$this->dropbox = new \Dropbox\API($this->OAuth);
	}
	
	public function get_account_info()
	{
		// Retrieve the account information
		return $this->dropbox->accountInfo();
	}
	
	public function get_directory( $path = '' )
	{
		$data = $this->get_metadata( $path );
		
		$contents = $data['body']->contents;
				
		return $contents;
	}
	
	public function get_metadata( $path = '')
	{
		$path = $this->base_dir . $path;
		
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

		// Upload the file with an alternative filename
		$put = $this->dropbox->putFile($tmp, $this->base_dir . $path . $name . '.' . $extension);

		// Unlink the temporary file
		unlink($tmp);
		
	}
	
}

?>