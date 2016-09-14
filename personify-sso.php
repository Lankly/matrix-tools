<?php
/*
Plugin Name: Personify SSO
Plugin URI: https://www.github.com/Lankly/matrix-tools/tree/personify-sso
Description: Single-Sign-on with Personify
Version: 0.1
Author: Matrixgroup International, Inc
Author URI: http://matrixgroup.net
*/

function personify_auth( $user, $username, $password ){
    // Make sure a username and password are present for us to work with
    if($username == '' || $password == '') return;

	$user = get_user_by('login', $username);
	
	// process the "usual" login through the login form
	if (! $user || in_array('member', $user->roles ) ){
		$auth = array( 
		'username' => $username, 
		'password' => $password, 
	 
		) ;
		$personify_client = new SoapClient("http://dev.mcaa.org/securitylogin.asmx?wsdl");
		$result = $personify_client->Authenticate($auth);
		$token = $result->AuthenticateResult->Token;

		 if( $token == '' || $result->AuthenticateResult->MemberOnlyAccess != 1 ) {
			// User does not exist,  send back an error message
			$user = new WP_Error( 'denied', __("ERROR: Username or password is incorrect") );
	
		 } else {
			 // External user exists, try to load the user info from the WordPress user table
			
			 if( ! $user ) {
				 // The user does not currently exist in the WordPress user table.
				 // You have arrived at a fork in the road, choose your destiny wisely
	
				 // If you do not want to add new users to WordPress if they do not
				 // already exist uncomment the following line and remove the user creation code
				 //$user = new WP_Error( 'denied', __("ERROR: Not a valid user for this system") );
	
				 // Setup the minimum required user information for this example
				 $userdata = array( 'user_email' =>  $result->AuthenticateResult->Email,
									'user_login' =>  strtolower($result->AuthenticateResult->Username),
									'role' => 'member'
									);
				 $new_user_id = wp_insert_user( $userdata ); // A new user has been created
	
				 // Load the new user info
				 $user = new WP_User ($new_user_id);
					 
				wp_update_user(array(
					'ID'            => $new_user_id,
					'first_name' =>  $result->AuthenticateResult->FirstName,
					'last_name' =>  $result->AuthenticateResult->LastName,
					'display_name' =>  $result->AuthenticateResult->FirstName,
					'nickname' =>  $result->AuthenticateResult->FirstName,
					'show_admin_bar_front'  =>  'false'
				));
			 } else {
					
				wp_update_user(array(
					'ID'            => $user->ID,
					'first_name' =>  $result->AuthenticateResult->FirstName,
					'last_name' =>  $result->AuthenticateResult->LastName,
					'display_name' =>  $result->AuthenticateResult->FirstName,
					'nickname' =>  $result->AuthenticateResult->FirstName,
					'show_admin_bar_front'  =>  'false'
				));
			 }
	
		 
			 if( !session_id() ) {
				session_start();
			 }
			 
			 $_SESSION['mcaa_token'] = $token;
		 }

		 // Comment this line if you wish to fall back on WordPress authentication
		 // Useful for times when the external service is offline
		 remove_action('authenticate', 'wp_authenticate_username_password', 20);
		 

		 return $user;
	}
	
}

add_filter( 'authenticate', 'personify_auth', 10, 3 );

function personify_login_redirect(){
	global $post;

	if (function_exists('members_can_current_user_view_post') && !empty($post)){
		if (!is_user_logged_in()
            && !members_can_current_user_view_post($post->ID)) {
			$permalink = get_permalink($post->ID);
			wp_redirect( home_url( '/wp-login.php?redirect_to='.$permalink) );
			exit();
		}
     }
}
add_action( 'template_redirect', 'personify_login_redirect' );


function personify_logout() {
	if ( isset($_COOKIE['RiSEMatrixAuth'] ) ){
		unset($_COOKIE['RiSEMatrixAuth']);
		setcookie('RiSEMatrixAuth', null, -1, '/');
	}
}
add_action('wp_logout', 'personify_logout');

function personify_post_authentication() {
	if ( isset($_SESSION['mcaa_token']) ){
		$targetPage = ( $_POST['redirect_to'] != home_url( '/wp-admin/') ) ? $_POST['redirect_to'] : home_url( '/');
		wp_redirect( 'http://dev.mcaa.org/MCAA/SSOLogin.aspx?token='.$_SESSION['mcaa_token'].'&returnUrl='.$targetPage );
		exit();
	}
}
add_action('wp_login', 'personify_post_authentication');

function keep_me_logged_in( $ttl, $user_id, $remember ) {
	$user = get_user_by('id', $user_id);
	
	if ( $user && ! in_array('administrator', $user->roles ) ){
	
	   if ( date("m") < 3  ||  (date("m") == 3 && date("d") < 28) )
				$expirationYear = date("Y");
			else
				$expirationYear = date("Y")+1; 
	   $ttl = strtotime(''.$expirationYear.'-03-29 23:59:59 EST') - time();
   }
   return $ttl;
}
add_filter( 'auth_cookie_expiration', 'keep_me_logged_in', 10, 3 );
?>


