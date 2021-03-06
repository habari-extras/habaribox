<?php

class HabariBox extends Plugin implements MediaSilo
{
	const SILO_NAME = 'Dropbox';

	static $cache = array();
	
	private static $key = 'slimassr302930tas2v'; // the app key, replace for yours ;)
	private static $secret = 'sd552asg5sa'; // the app secret
	
	/**
	 * Find out whether we should show the media silo 
	 **/
	private function show_media_silo()
	{
		return Options::get('habaribox__silo');
	}
	
	/**
	 * Check that we've initiated all posts before doing anything else 
	 **/
	private function is_initiated()
	{
		$yes = Options::get('habaribox__sync');
		
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
		self::$key = 'Nm80M23FnM3F1UTRibDgx';
		self::$secret = 'Nm80M1FnM3F1UTRibDgx';
		self::$key = 'aXJlZXN5cmlvdDV0ZlEy';
		
		if( !$this->is_initiated() )
		{
			$this->check_posts();
		}
		
		$this->evaluate_posts( false );
		
		$this->silo_dir( '/Public' );
		
		$this->add_rule('"dropbox_list"/directory', 'dropbox_list');
		
		$this->add_template('directory_list', dirname(__FILE__) . '/directory_list.php');
		
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
	 * Implement the shortcode to publicly list the contents of a directory
	 */
	function filter_shortcode_dropbox($content, $code, $attrs, $context)
	{	
		$path = $attrs['path'];
		
		// deal with a directory list
		if( isset( $attrs['show'] ) && $attrs['show'] == 'list' )
		{
			if( Cache::has( array( 'habaribox_dirlist', $path ) ) )
			{
				$files = Cache::get( array( 'habaribox_dirlist', $path ) );
			}
			else
			{
				$files = $this->api->get_directory( $path, false );
			
				foreach( $files as $name => $file )
				{
					$file->link = $this->api->get_link( $file->path );
				}
				
				Cache::set( array( 'habaribox_dirlist', $path ), $files, (empty($attrs['expiry'])) ? 3600 : $attrs['expiry'] );
				
			}
			
			$theme = Themes::create();
			$theme->assign('files', $files);
						
			return $theme->fetch('directory_list');
		}
		// simply generate a public link
		else
		{			
			if( Cache::has( array( 'habaribox_link', $path ) ) )
			{
				$link = Cache::get( array( 'habaribox_link', $path ) );
			}
			else
			{
				$link = $this->api->get_link( $path );
				
				Cache::set( array( 'habaribox_link', $path ), $link, (empty($attrs['expiry'])) ? 3600 : $attrs['expiry'] );
			}
			
			if( isset( $attrs['show'] ) && $attrs['show'] != 'link' )
			{
				if( $attrs['show'] == 'image' || $attrs['show'] == 'imageurl' )
				{
					if( Cache::has( array( 'habaribox_image', $path ) ) )
					{
						$image = Cache::get( array( 'habaribox_image', $path ) );
					}
					else
					{
						$request = new RemoteRequest( $link );
						if( $request->execute() )
						{
							$headers = $request->get_response_headers();
							$image = str_replace( 'www.', 'dl.', $headers['location'] );
						}

						Cache::set( array( 'habaribox_image', $path ), $image, (empty($attrs['expiry'])) ? 3600 : $attrs['expiry'] );
					}
				}
				elseif( $attrs['show'] == 'thumb' || $attrs['show'] == 'thumburl' )
				{
					if( Cache::has( array( 'habaribox_thumbnail', $path ) ) )
					{
						$image = Cache::get( array( 'habaribox_thumbnail', $path ) );
					}
					else
					{
						$image = $this->api->get_thumbnail( $path );

						Cache::set( array( 'habaribox_thumbnail', $path ), $image, (empty($attrs['expiry'])) ? 3600 : $attrs['expiry'] );
					}
				}
				
				
				if( $attrs['show'] == 'imageurl' || $attrs['show'] == 'thumburl'  )
				{
					// return $image;
				}
				else
				{					
					return '<a href="' . $link . '"><img src="' . $image . '" alt="' . ( (empty($context)) ? $link : $context ). '" /></a>';
				}
				
			}
			elseif( isset( $attrs['show'] ) && $attrs['show'] == 'url' )
			{
				return $link;
				
			}
			else
			{
				return '<a href="' . $link . '">' . ( (empty($context)) ? $link : $context ). '</a>';
				
			}
									
			
		}
		
	}
	
	/**
	 * Expire the cache when you go to edit a specific post
	 **/
	public function action_form_publish( $form, $post, $context )
	{
		if( !$this->create_api() )
		{
			return;
		}
		
		Cache::expire( array('habaribox', 'dropbox_posts' ) );

		$this->evaluate_posts( true ); // force a reevaluation of posts
		
		if( $post->id != 0 )
		{
			$d_post = $this->api->get_file( $post->slug );

			$form->content->value = $d_post['data'];

			// Utils::debug( $d_post );

			$form->post_links->append( 'static', 'dropbox_sync', sprintf( _t( 'Synced from Dropbox: %s' ), HabariDateTime::date_create($d_post['meta']->modified)->format() ) );
		}
		
	}
	
	private function update_habari_content( $post )
	{
		$file_content = $this->api->get_file_contents( $post->slug );
		// Cache::set( array('habaribox', $post->slug ), $file_content );
				
		$post->content = $file_content;
		$post->update();
		
		// Utils::redirect();
	}
		
	/**
	 * Evaluate the synced status of posts 
	 **/
	public function evaluate_posts( $force = false)
	{		
		if( !Cache::has( array('habaribox', 'dropbox_posts' ) ) || $force == true ) // Only evaluate if there isn't anything in the cache
		{			
			if( !$this->create_api() )
			{
				return;
			}

			$dropbox_posts = $this->api->get_directory( $this->get_storage_directory(), false );
			// $habari_posts = Posts::get( array('nolimit' => true ) );
			
			Cache::set( array('habaribox', 'dropbox_posts' ), $dropbox_posts );

			// Utils::debug( $dropbox_posts );

			foreach( $dropbox_posts as $slug => $d_post )
			{
				$h_post = Posts::get( array( 'slug' => $slug, 'ignore_permissions' => true ) );
				if( isset( $h_post[0] ) )
				{
					$h_post = $h_post[0];
				}
				else
				{
					$h_post = false;
				}
				$this->check_post( $h_post, $d_post );
			}
		}
				
		// Utils::debug( $directory );
	}
	
	/**
	 * Checks all posts for their DropBox status and creates/updates the DropBox file if necessary 
	 **/
	private function check_posts()
	{
		// return; // fix this later
		
		if( Options::get('habaribox__oauth-token') == false || !Options::get('habaribox__sync') )
		{
			return;
		}
		
		// return;
		
		$this->create_api();
		
		$posts = Posts::get( array( 'nolimit' => true ) );
		
		// $posts = array( $posts[0] ); // for testing, just use first post
		
		$base_dir_contents = $this->api->get_directory('', false);
		
		if( !isset( $base_dir_contents[ $this->get_storage_directory() ] ) )
		{
			// Utils::debug( $)
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
	private function check_post( $h_post, $meta = null )
	{
		$this->create_api();
				
		if( $meta == null ) // we don't know about Dropbox, so let's check
		{
			$list = $this->api->get_directory();

			// Utils::debug( $list );

			if(isset( $list[$h_post->slug] ) )
			{
				// handle the post if it already exists
				// $this->api->update_file( $post->slug, $post->content );
			}
			else
			{
				// Utils::debug( $post );

				$this->api->create_file( $h_post->slug, $h_post->content );

			}
		}
		elseif( $h_post == false) // the Habari post doesn't exist so it must be created
		{
			// Utils::debug( $h_post, $meta );
			
			$user = User::identify();
			
			if( $user->loggedin == false )
			{
				return; // only bother to create the post on next login
			}
			
			$path = pathinfo( $meta->path );
			$slug = $path['filename'];			
			$content = $this->api->get_file_contents( $slug );
			Cache::set( array('habaribox', $slug ), $content );

			$h_post = Post::create( array(
				'title' => $slug,
				'slug' => $slug,
				'content' => $content,
				'user_id' => $user->id
			) );

			// $post->content = $file_content;
			// $post->update();
			
			// Utils::debug( $meta, $path, $h_post, $content );
		}
		else // compare the Habari and Dropbox versions
		{
			
			$dropbox_date = HabariDateTime::date_create( $meta->modified );
			$time_diff = HabariDateTime::difference( $dropbox_date, $h_post->modified );
			
			
			if( $time_diff['invert'] != true && $time_diff['s'] > 10)
			{					
				// Utils::debug( $time_diff, $h_post );
				
				// our copy is out of sync
				$this->update_habari_content( $h_post );
				// Utils::debug( $time_diff, $dropbox_date, $post->modified, $post );
			}
		}
		
	}
	
	/**
	 * Creates a DropBox API object if one doesn't already exist 
	 **/
	private function create_api( $create_token = false, $purpose = 'backup' )
	{		
		if( Options::get('habaribox__oauth-token') == null && $create_token == false )
		{
			return false;
		}
				
		if( !isset( $this->api ) )
		{
			$replace = array( '5', '6', '3', '4', 'Q');
			$search = array( '0', '5', '2', '6', 'v' );
			
			$base_dir = $this->get_storage_directory() . '/';
			$sdk_base = dirname( $this->get_file() ) . '/dropbox-library/Dropbox/';
			$key = str_replace( $replace, $search, base64_decode( self::$key ) );
			$secret = str_replace( $replace, $search, base64_decode( self::$secret ) );
			
			// Utils::debug( $key, $secret );
			
			if( Options::get('habaribox__oauth-token') != null && $create_token == false )
			{
				$session = new stdClass;
				$session->oauth_token_secret = Options::get('habaribox__oauth-secret');
				$session->oauth_token = Options::get('habaribox__oauth-token');
				$session->uid = Options::get('habaribox__oauth-uid');
			}
			else
			{
				$session = null;
			}
			
			// $session = null;
						
			$this->api = new DropboxAPI( $sdk_base, $base_dir, $key, $secret, $session);
		}
		
		if( $create_token )
		{
			$token_class = $this->api->get_token();
			Options::set( 'habaribox__oauth-token', $token_class->oauth_token);
			Options::set( 'habaribox__oauth-secret', $token_class->oauth_token_secret);
			Options::set( 'habaribox__oauth-uid', $token_class->uid);
		}
		
		if( $purpose == 'backup' )
		{
			return $this->is_initiated();
		}
		else
		{
			return $this->show_media_silo();
		}
	}
	
	/**
	 * THIS HAS BEEN REPLACED WITH A SHORTCODE
	 * Filter post content to replace links for Dropbox Public files
	 *
	 * For file * in the public directory, with public link #:
	 * 	- <dP=*> is replaced with the public link to #
	 *  - <diP=*> is replaced with <img src="#">
	 *  - <daP=*> is replaced with <a href="#">
	 */
	public function filter_post_content_out( $content )
	{	
		$pattern = '/\<dP\=([A-Za-z0-9-.]+)\>/';
		$replacement = 'http://dl.dropbox.com/u/' . Options::get( 'habaribox__oauth-uid' ) . '/' . '$1';
		$content =  preg_replace($pattern, $replacement, $content);
		
		$pattern = '/\<diP\=([A-Za-z0-9-.]+)\>/';
		$replacement = '<img src="http://dl.dropbox.com/u/' . Options::get( 'habaribox__oauth-uid' ) . '/' . '$1' . '">';
		$content =  preg_replace($pattern, $replacement, $content);
		
		$pattern = '/\<daP\=([A-Za-z0-9-.]+)\>/';
		$replacement = '<a href="http://dl.dropbox.com/u/' . Options::get( 'habaribox__oauth-uid' ) . '/' . '$1' . '">';
		$content =  preg_replace($pattern, $replacement, $content);
		
		return $content;
	}
	
	public function filter_plugin_config( $actions )
	{
		$actions['configure'] = _t('Configure');
		$actions['authorize'] = _t('Authorize');
		return $actions;
	}
	
	public function action_plugin_ui_configure()
	{
		$ui = new FormUI( strtolower( get_class( $this ) ) );
		$secret = $ui->append( 'text', 'oauth-secret', 'habaribox__oauth-secret', _t('OAuth Secret:') );
		$token = $ui->append( 'text', 'oauth-token', 'habaribox__oauth-token', _t('OAuth Token:') );
		$uid = $ui->append( 'text', 'oauth-uid', 'habaribox__oauth-uid', _t('OAuth UID:') );
		
		$sync = $ui->append( 'checkbox', 'enable-sync', 'habaribox__sync', _t('Enable sync') );
		$silo = $ui->append( 'checkbox', 'enable-silo', 'habaribox__silo', _t('Enable silo') );
				
		$ui->append('submit', 'save', _t('Save'));
		$ui->out();
	}
	
	public function action_plugin_ui_authorize()
	{
		// echo 'jim';
				
		// session_destroy();
		$this->create_api( true );
		Session::notice( 'Authorization tokens have been successfully set.' );
	}
	
	// silo parts start here

	/**
	* Return basic information about this silo
	*     name- The name of the silo, used as the root directory for media in this silo
	*	  icon- An icon to represent the silo
	*/
	public function silo_info()
	{
		if ( $this->is_auth() ) {
			return array( 'name' => self::SILO_NAME, 'icon' => URL::get_from_filesystem(__FILE__) . '/icon.png' );
		}
		else {
			return array();
		}
	}

	/**
	* Return directory contents for the silo path
	*
	* @param string $path The path to retrieve the contents of
	* @return array An array of MediaAssets describing the contents of the directory
	*/
	public function silo_dir( $path )
	{		
		if( !$this->create_api( false, 'silo' ) )
		{
			return;
		}
		
		$path = preg_replace( '%\.{2,}%', '.', $path );
		$results = array();
		
		// Utils::de
		
		// $path = '/Public';
				
		$contents = $this->api->get_directory( $path, false );
		
		foreach( $contents as $item )
		{	
			$props = array( 'title' => basename($item->path) );
			
			if( $item->is_dir )
			{
				
				$results[] = new MediaAsset( self::SILO_NAME . $item->path, true );
			}
			else
			{				
				$props = $this->silo_file_properties( $item );
				
				$results[] = new MediaAsset(
					self::SILO_NAME . $item->path ,
					false,
					$props
				);
			}
		}

		return $results;
	}

	/**
	* Get the file from the specified path
	*
	* @param string $path The path of the file to retrieve
	* @param array $qualities Qualities that specify the version of the file to retrieve.
	* @return MediaAsset The requested asset
	*/
	public function silo_get( $path, $qualities = null )
	{
	}
	
	private function silo_file_properties( $meta )
	{
		$props = array();
		// $props = array_merge( $props, self::element_props( $photo, "http://www.flickr.com/photos/{$_SESSION['nsid']}/{$photo['id']}", $size ) );
		
		$path = pathinfo( $meta->path );
				
		// if( $meta->thumb_exists)
		$props['relpath'] = $meta->path;
		$props['filetype'] = 'dropbox';
		
		if( $meta->thumb_exists )
		{
			$props['thumbnail_url'] = $this->silo_thumbnail( $meta->path );
			$props['filetype'] = 'dropbox_image';
			
			// print_r( $path );
			
		}
		else
		{
			$url = URL::get_from_filesystem( $this->get_file() ) . '/icons/' . $meta->icon . '48.gif';
			
			$props['thumbnail_url'] = $url;
		}	
		
		return $props;
	}
	
	/**
	* Get the thumbnail for a specified file
	*
	*/
	private function silo_thumbnail( $path )
	{		
		$thumbnail = $this->api->get_thumbnail( $path );
		
		return $thumbnail;
	}
	

	/**
	* Get the direct URL of the file of the specified path
	*
	* @param string $path The path of the file to retrieve
	* @param array $qualities Qualities that specify the version of the file to retrieve.
	* @return string The requested url
	*/
	public function silo_url( $path, $qualities = null )
	{	
		if( strpos( $path, '/Public' ) === 0 )
		{
			// path is in public folder, so quicker direct URL can be built
			$link = $this->api->get_public_link( $path );
		}
		else
		{
			$link = '[dropbox link="false" path=" ' . $path . '"/]';
			// $link = $this->api->get_link( $path );
		}
				
		return $link;
		
		// $photo = false;
		// if ( preg_match( '%^photos/(.+)$%', $path, $matches ) ) {
		// 	$id = $matches[1];
		// 	$photo = self::$cache[$id];
		// }
		// 
		// $size = '';
		// if ( isset( $qualities['size'] ) && $qualities['size'] == 'thumbnail' ) {
		// 	$size = '_m';
		// }
		// $url = "http://farm{$photo['farm']}.static.flickr.com/{$photo['server']}/{$photo['id']}_{$photo['secret']}{$size}.jpg";
		// return $url;
	}

	/**
	* Create a new asset instance for the specified path
	*
	* @param string $path The path of the new file to create
	* @return MediaAsset The requested asset
	*/
	public function silo_new( $path )
	{
	}

	/**
	* Store the specified media at the specified path
	*
	* @param string $path The path of the file to retrieve
	* @param MediaAsset $ The asset to store
	*/
	public function silo_put( $path, $filedata )
	{
	}

	/**
	* Delete the file at the specified path
	*
	* @param string $path The path of the file to retrieve
	*/
	public function silo_delete( $path )
	{
	}

	/**
	* Retrieve a set of highlights from this silo
	* This would include things like recently uploaded assets, or top downloads
	*
	* @return array An array of MediaAssets to highlihgt from this silo
	*/
	public function silo_highlights()
	{
	}

	/**
	* Retrieve the permissions for the current user to access the specified path
	*
	* @param string $path The path to retrieve permissions for
	* @return array An array of permissions constants (MediaSilo::PERM_READ, MediaSilo::PERM_WRITE)
	*/
	public function silo_permissions( $path )
	{
	}

	/**
	* Return directory contents for the silo path
	*
	* @param string $path The path to retrieve the contents of
	* @return array An array of MediaAssets describing the contents of the directory
	*/
	public function silo_contents()
	{
		// $flickr = new Flickr();
		// 		$token = Options::get( 'flickr_token_' . User::identify()->id );
		// 		$result = $flickr->call( 'flickr.auth.checkToken',
		// 			array( 'api_key' => $flickr->key,
		// 				'auth_token' => $token ) );
		// 		$photos = $flickr->GetPublicPhotos( $result->auth->user['nsid'], null, 5 );
		// 		foreach( $photos['photos'] as $photo ){
		// 			$url = $flickr->getPhotoURL( $photo );
		// 			echo '<img src="' . $url . '" width="150px" alt="' . ( isset( $photo['title'] ) ? $photo['title'] : _t('This photo has no title') ) . '">';
		// 		}
		
		echo 'jim';
	}
	
	/**
	 * Produce a link for the media control bar that causes a specific path to be displayed
	 *
	 * @param string $path The path to display
	 * @param string $title The text to use for the link in the control bar
	 * @return string The link to create
	 */
	public function link_path( $path, $title = '' )
	{
		if ( $title == '' ) {
			$title = basename( $path );
		}
		return '<a href="#" onclick="habari.media.fullReload();habari.media.showdir(\''.$path.'\');return false;">' . $title . '</a>';
	}

	/**
	 * Produce a link for the media control bar that causes a specific panel to be displayed
	 *
	 * @param string $path The path to pass
	 * @param string $path The panel to display
	 * @param string $title The text to use for the link in the control bar
	 * @return string The link to create
	 */
	public function link_panel( $path, $panel, $title )
	{
		return '<a href="#" onclick="habari.media.showpanel(\''.$path.'\', \''.$panel.'\');return false;">' . $title . '</a>';
	}

	/**
	 * Provide controls for the media control bar
	 *
	 * @param array $controls Incoming controls from other plugins
	 * @param MediaSilo $silo An instance of a MediaSilo
	 * @param string $path The path to get controls for
	 * @param string $panelname The name of the requested panel, if none then emptystring
	 * @return array The altered $controls array with new (or removed) controls
	 *
	 * @todo This should really use FormUI, but FormUI needs a way to submit forms via ajax
	 */
	public function filter_media_controls( $controls, $silo, $path, $panelname )
	{
		$class = __CLASS__;
		if ( $silo instanceof $class ) {
			$controls[] = $this->link_path( self::SILO_NAME . '/Public', _t( 'Public' ) );
			$controls[] = $this->link_path( self::SILO_NAME . '/' . $path, _t( 'Browse' ) );
			$controls[] = '<a href="#" onclick="habari.media.insert_list_dropbox_directory(\''.$path.'\');return false;">' . _t( 'Insert directory list' ) . '</a>';
			
			// if ( User::identify()->can( 'upload_media' ) ) {
			// 	$controls[] = $this->link_panel( self::SILO_NAME . '/' . $path, 'upload', _t( 'Upload' ) );
			// }
			// if ( User::identify()->can( 'create_directories' ) ) {
			// 	$controls[] = $this->link_panel( self::SILO_NAME . '/' . $path, 'mkdir', _t( 'Create Directory' ) );
			// }
			// if ( User::identify()->can( 'delete_directories' ) && ( $path && self::isEmptyDir( $this->root . '/' . $path ) ) ) {
			// 	$controls[] = $this->link_panel( self::SILO_NAME . '/' . $path, 'rmdir', _t( 'Delete Directory' ) );
			// }
		}
		return $controls;
	}
	
	public function action_admin_footer( $theme ) 
	{
		if ( Controller::get_var( 'page' ) == 'publish' ) {

			echo <<< HABARIBOX
			<script type="text/javascript">
				habari.media.output.dropbox = {
					insert_link: function(fileindex, fileobj) {
						habari.editor.insertSelection('[dropbox path="' + fileobj.relpath + '"]' + fileobj.title + '[/dropbox]');
					},
					insert_thumbnail: function(fileindex, fileobj) {
						console.log( fileobj );
						habari.editor.insertSelection('[dropbox path="' + fileobj.relpath + '"]<img src="' + fileobj.thumbnail_url + '" alt="' + fileobj.title + '" />[/dropbox]');
					}
				}
				habari.media.output.dropbox_image = {
					insert_thumbnail: function(fileindex, fileobj) {
						habari.editor.insertSelection('[dropbox path="' + fileobj.relpath + '" show="thumb"/]');
					},
					insert_image: function(fileindex, fileobj) {
						habari.editor.insertSelection('[dropbox path="' + fileobj.relpath + '" show="image"/]');
					},
					insert_imageurl: function(fileindex, fileobj) {
						habari.editor.insertSelection('[dropbox path="' + fileobj.relpath + '" show="imageurl"/]');
					}
				}
				habari.media.insert_list_dropbox_directory = function( path ) {
					habari.editor.insertSelection('[dropbox path="/' + path + '" show="list"/]');
				}
			</script>
HABARIBOX;
		}
	}

	private function is_auth()
	{
		return $this->show_media_silo();
	}

}

class DropboxAPI
{
	
	private $key;
	private $secret;
		
	public function __construct( $sdk_base, $directory, $key, $secret, $session = null )
	{
		$this->base_dir = $directory;
		$this->sdk_base = $sdk_base;
		$this->key = $key;
		$this->secret = $secret;
		
		// Check whether to use HTTPS and set the callback URL
		$this->protocol = (!empty($_SERVER['HTTPS'])) ? 'https' : 'http';
		$this->callback = Site::get_url( 'host' ) . Controller::get_full_url();
		
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
		if( $session != null )
		{
			// Utils::debug( $session, $token );
			
			$this->storage->set( $session, 'access_token' );
		}
		// // 
		$this->OAuth = new \Dropbox\OAuth\Consumer\Curl($this->key, $this->secret, $this->storage, $this->callback);
		
		$this->dropbox = new \Dropbox\API($this->OAuth, 'dropbox');
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
			
			$path = pathinfo( $content->path );
			if( $path['filename'] != ''  )
			{
				$list[ Utils::slugify( $path['filename'] ) ] = $content;
			} 
			
		}
			
		return $list;
	}
	
	public function get_metadata( $path = '/' )
	{
		$path = $path;
		
		$data = $this->dropbox->metaData($path);
				
		return $data;
		
	}
	
	public function get_file( $name, $extension = 'html', $path = '' )
	{		
		// Utils::debug( $this->base_dir . $path . $name . '.' . $extension );
		
		$data = $this->dropbox->getFile( $this->base_dir . $path . $name . '.' . $extension );
		
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
	
	public function get_thumbnail($file, $format = 'JPEG', $size = 'large')
	{
		
		$response = $this->dropbox->thumbnails( $file, $format, $size );
		
		$url = "data:" . str_replace( ' ', '', $response['mime'] ) . ";base64," . base64_encode( $response['data'] );
		
		// echo '<img src="' . $url . '">';
		// data:image/jpeg;charset=binary;base64,
		
		// Utils::debug( $res)
		
		// <img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAQAAAADCAIAAAA7ljmRAAAAGElEQVQIW2P4DwcMDAxAfBvMAhEQMYgcACEHG8ELxtbPAAAAAElFTkSuQmCC" />
		
		
		// Utils::debug( $response, $url );
		
		return $url;
	}
	
	public function get_link($file)
	{
		$response = $this->dropbox->shares($file);
		
		return $response['body']->url;
	}
		
	public function get_token( $type = 'access_token' )
	{
		return $this->storage->get( $type );
	}
	
	public function get_public_link( $path )
	{		
		return 'http://dl.dropbox.com/u/' . Options::get( 'habaribox__oauth-uid' ) . '/' . substr( $path, 8);
	}
	
}

?>