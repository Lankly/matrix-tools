<?php
/*
  Plugin Name: NIA Data Migration
  Plugin URI:  http://github.com/Lankly/matrix-tools/tree/nia-data-migration
  Description: Migrates all the issues of Police Chief Magazine to WordPress.
  Version:     0.1
  Author:      Matrix Group International
  Author URI:  http://matrixgroup.net/
  License:     GPL2
  License URI: https://www.gnu.org/licenses/gpl-2.0.html
  Text Domain: nia-migration
  Domain Path: /languages
*/

error_reporting( E_ALL );
ini_set( 'display_errors', true );

/* OPTIONS */
/* These are all called "Debug" not because they do any debugging on their own,
 * but rather because you should use them while debugging.
 */
define("DEBUG", true);          /* Set to false to disable all other DEBUGs. */
define("DEBUG_CREDS", DEBUG && false);        /* Echo credentials information */
define("DEBUG_DATA", DEBUG && false);         /* Echo all exported data. */
define("DEBUG_FIRST_LINE", DEBUG && false);   /* Echo first line information. */
define("DEBUG_INSERT", DEBUG && false);       /* Echo each inserted post */
define("DEBUG_QUERY", DEBUG && false);        /* Echo complete query. */
define("DEBUG_QUERY_RESULTS", DEBUG && false);/* Echo the query results. */

define("DONT_FILTER_POSTS", true);     /* Disable WP's filter before insert? */
define("IMPORT_PUBLISHED", true); /* Should each imported post be published?  */
/* If a custom post, change this to its identifier */
define("POST_TYPE", "articles"); 
define("USE_CONNECTION_POOLING", false); /* Make a new connection every time? */
define("USE_UTF_8_CONVERSION", true);   /* Filter each field through UTF-8? */
define("USE_WINDOWS_AUTH", false);      /* Use Windows Auth to connect to db? */

function display_options(){
    //<br>s are separate so you can find-replace them with PHP_EOL easily.
    if(DEBUG_CREDS){
        echo "Credentials debugging enabled." . "<br>";
    }
    if(DEBUG_DATA){
        echo "Data debugging enabled." . "<br>";
    }
    if(DEBUG_FIRST_LINE){
        echo "First-line debugging enabled." . "<br>";
    }
    if(DEBUG_INSERT){
        echo "Insert-post debugging enabled." . "<br>";
    }
    if(DEBUG_QUERY){
        echo "Query debugging enabled." . "<br>";
    }
    if(DEBUG_QUERY_RESULTS){
        echo "Query results debugging enabled" . "<br>";
    }
    if(DEBUG_CREDS
       || DEBUG_DATA
       || DEBUG_FIRST_LINE
       || DEBUG_INSERT
       || DEBUG_QUERY
       || DEBUG_QUERY_RESULTS){
        echo "<br>";
    }
    if(DONT_FILTER_POSTS){
        echo "WordPress's insertion filter disabled." . "<br>";
    }
    if(IMPORT_PUBLISHED){
        echo "Each imported post will be set to published." . "<br";
    }
    if(USE_CONNECTION_POOLING){
        echo "Connection Pooling for database connection enabled." . "<br>";
    }
    if(USE_UTF_8_CONVERSION){
        echo "Conversion through UTF-8 enabled." . "<br>";
    }
    if(USE_WINDOWS_AUTH){
        echo "Windows Authorization for database connection enabled." . "<br>";
    }
    if(USE_CONNECTION_POOLING
       || USE_WINDOWS_AUTH
       || USE_UTF_8_CONVERSION
       || DONT_FILTER_POSTS
       || IMPORT_PUBLISHED){
        echo "<br>";
    }
}

/* CONSTANTS */
define("DEBUG_LINE_WIDTH", 80);
define("FIELD_NOT_REQUIRED", false);
define("FIELD_REQUIRED", true);

/* This class is basically just a struct for holding information about field
 * names and where they are.
 */
class NIAField {
  public $orig_name;     /* Field in the csv */
  public $new_name;      /* Field in the WP db */
  public $required;      /* Whether or not the field can be blank */
  public $is_cust_field; /* True if this is a custom field in WP */
  public $strip_html; /* True if this field should strip out all HTML tags */
  public $is_array; /* True if comma-delineated and needs to be an array */
  function __construct($orig_name,
                       $new_name,
                       $req,
                       $cust = false,
                       $html = false,
                       $array = false){
    $this->orig_name = $orig_name;
    $this->new_name = $new_name;
    $this->required = $req;
    $this->is_cust_field = $cust;
    $this->strip_html = $html;
    $this->is_array = $array;
  }
}


/* GLOBALS */

/* Every field in this array is one that will be mapped to a field in the new
 * database. If any of the fields still have the default value after the file
 * has been read, the program should fail.
 *
 * The first parameter is the name of a column from the query we're exporting
 * from. The second is that same field in the database we're importing to. If
 * you want two fields to become one, change the query to combine them first.
 *
 * Please see the documentation for wp_insert_post(). The second field should
 * be one of the names under $postarr or the name of a custom field. If it is
 * a custom field, "true" should be passed as a fourth parameter.
 */
$nia_fields_array = [
  new NIAField("BaseURL", "", FIELD_REQUIRED),
  new NIAField("FileName", "", FIELD_REQUIRED),
  new NIAField("ImageCount", "", FIELD_REQUIRED),
  new NIAField("EndURL", "", FIELD_REQUIRED),
];


/* FUNCTIONS */

/* Small little function to generate the second argument for sqlsrv_connect().
 * It takes into account the options at the top of the file. Returns an array.
 */
function nia_get_connection_info($creds){
  $connectionInfo = [];
    
  $connectionInfo["Database"] = $creds["database"];
  if(!USE_CONNECTION_POOLING){
    $connectionInfo["ConnectionPooling"] = 0;
  }
  if(!USE_WINDOWS_AUTH){
    $connectionInfo["UID"] = $creds["username"];
    $connectionInfo["PWD"] = $creds["password"];
  }

  return $connectionInfo;
}

/* Takes in the name of an image, minus the extension, like: IO031201_01
 */
function nia_get_file_caption($file){
    $creds = nia_read_credentials();
    $connectionInfo = nia_get_connection_info($creds);

    echo "Querying for caption of " . $file . "<br>";
    ini_set('mssql.charset', 'UTF-8'); //Turn on Unicode
    $conn = mssql_connect($creds["server"],
                          $creds["username"],
                          $creds["password"])
          or wp_die("Failed to connect.");
    mssql_select_db($creds["database"], $conn );

    $query = "SELECT PhotoCaption, PhotoID"
           . "FROM PhotoCaption"
           . "WHERE PhotoID LIKE '" . $file . "%'";
    $resp = mssql_query($query, $conn) or wp_die("Failed to query.");
    $num_rows = mssql_num_rows($resp);
    $ret = "";
    
    if($num_rows <= 0){
        echo "Failed to return any results!" . "<br>";
    }
    else{
        $obj = mssql_fetch_array($resp);
        $caption = $obj["PhotoCaption"];

        if(is_null($caption)){
            echo "Invalid result!" . "<br>";
        }
        else{
            $ret = $caption;
        }
    }
    
    if(!empty($conn)){
        mssql_close($conn);
    }
    
    return "";
}

/* Returns the query in 'query.txt' to be performed on the database in
 * 'credentials.txt'.
 *
 * Each row of the result should contain all the columns in $nia_fields_array,
 * but only the required columns are necessary.
 */
function nia_read_query(){
  $filename = plugin_dir_path( __FILE__ ) . "query.txt";
  $f = fopen($filename, "r") or wp_die("Unable to read query file, "
                                       . $filename . "!");
  $query = "";

  //Just want to turn the whole file into a single string.
  while(!empty($line = fgets($f))){
    $query = $query . $line;
  }

  //Don't forget to close the file!
  if($f){
    fclose($f);
  }
    
  return $query;
}

/* Returns the credentials in the credential file in an associative array.
 */
function nia_read_credentials(){
  $to_return = [
    "server" => "",
    "database" => "",
    "table" => "",
    "username" => "",
    "password" => ""
  ];
    
  $filename = plugin_dir_path( __FILE__ ) . "credentials.txt";
  $f = fopen($filename, "r") or wp_die("Unable to read credentials file, "
                                       . $filename . "!");

  if(DEBUG_CREDS){echo "Beginning read..." . "<br>";}

  //Run through the file and put each matching line into $to_return
  while(!empty($line = fgets($f))){
    //No need for a "<br>" on this echo since $line will contain a newline
    if(DEBUG_CREDS){
      echo substr($line, 0, strlen($line) - 2);
    }

    /* Each line in the credentials file is formatted like:
     *
     * field: value
     *
     * And each of the fields in $to_return should match to a field
     * somewhere in the credentials file. So, in order to determine
     * the field on each line, all we have to do is get the colon's
     * index on the line. Everything before the index is the field,
     * and everything after the index is the value.
     */
    $index = strpos($line, ":");
    $field = substr($line, 0, $index);
    $to_return[$field] = trim(substr($line, $index + 1));
        
    if(DEBUG_CREDS){
      echo " ("
        . $to_return[$field] . ")"
        . "<br>";
    }
  }

  //Don't forget to close the file!
  if($f){
    fclose($f);
  }
    
  //If any of the fields wasn't found, fail
  foreach((array)$to_return as $key => $field){
    if(empty($field)){
      wp_die("Missing " . $key . "!" . "<br>");
    }
  }
    
  if(DEBUG_CREDS){
    echo "Done read." . "<br>" . "<br>";
  }
    
  return $to_return;
}

/* We're going to grab the data from the specified database and break it up into
 * something we can use. This function is separate so that if we want to turn it
 * into something that performs operations directly on a csv file, we won't have
 * to change any other functions.
 *
 * Returns an array of arrays of strings that represent all the data that we are
 * trying to move. Each string is in the order of $nia_fields_array.
 */
function nia_get_data(){
  global $nia_fields_array;
  $creds = nia_read_credentials();
    
  //Create the connection information array, taking into account options
  $connectionInfo = nia_get_connection_info($creds);

  // Actually create connection
  echo "Connecting to " . $creds["server"] . "... ";
  ini_set('mssql.charset', 'UTF-8'); //Turn on Unicode
  $conn = mssql_connect($creds["server"],
                        $creds["username"],
                        $creds["password"])
        or wp_die("Failed.");
  mssql_select_db($creds["database"], $conn );
  echo "Connection opened." . "<br>" . "<br>";

  //Perform the query
  $query = nia_read_query();
  if(DEBUG_QUERY){ echo "Query: " . "<br>" . $query  . "<br>";}
  $resp = mssql_query($query, $conn) or wp_die("Query failed!");
  $num_rows = mssql_num_rows($resp);

  if($num_rows <= 0){
    wp_die("Query returned no results!");
  }
    
  //Process the result
  $to_return = [];
  while($obj = mssql_fetch_array($resp, MSSQL_ASSOC)){
    $line = [];

    /* If a required field is missing from the line, we want to skip adding
     * the line to $to_return. The easiest way to keep track of these lines
     * is with a boolean. We set it to false when we fail, and then we just
     * perform a quick check before adding the line to make sure it is okay
     * to add to $to_return.
     */
    $all_required_fields_accounted_for = true;
        
    /* The migration is expecting an array, so we can't just pass back the
     * object. This also does sort the array that we create into the order
     * outlined in the nia_fields_array.
     */
    foreach((array)$nia_fields_array as $field){
      //Grab the next field and drop it into the array
      $prop_name = $field->orig_name;
      $value = $obj[$prop_name];
            
      //Check for missing data. If required, skip the line. If not, N/A
      if(empty($value)){
        if($field->required == FIELD_REQUIRED){
          if(DEBUG_QUERY){
            echo "Missing required field! Skipping line." . "<br>";
          }
          $all_required_fields_accounted_for = false;
          break;
        }
        //I once had an else here that made empty nonrequired fields
        //be replaced with "N/A". So, if you want to do something
        //similar, I've let this structure be.
      }

      //Should we remove the HTML from this field?
      if($field->strip_html){
        $value = strip_tags($value);
      }

      //Does this field need to be an array?
      //automatic for post_category and tax_input
      if($field->is_array
         || strcmp($field->new_name, "post_category") == 0
         || strcmp($field->new_name, "tax_input") == 0)
      {
        $value = explode(",", $value);
      }
            
      //Add the value to the line's array
      $line[] = $value;
            
      if(DEBUG_QUERY_RESULTS){
        /* Since some fields can be excessively long, restrict them to
         * a pre-set, constant length.
         */
        echo str_replace("\n", " ", substr($value, 0, DEBUG_LINE_WIDTH))
          . ", ";
      }
    }

    if($all_required_fields_accounted_for){
      $to_return[] = $line;
    }
        
    //Two newlines after to guarantee readability
    if(DEBUG_QUERY_RESULTS){ echo "<br>" . "<br>"; }
  }

  echo count($to_return) . " records imported." . "<br>" . "<br>";
    
  // Close connection when done.
  if(!empty($conn)){
    echo "Closing connection...  ";
    mssql_close($conn);
  }
  echo "Connection Closed." . "<br>" . "<br>";
    
  return $to_return;
}

function nia_get_wp_post_from_lid($legacy_id){
    $args = array(
        'post_type' => POST_TYPE,
        'meta_query' => array(
            array(
                'key' => 'legacy_article_id',
                'value' => $legacy_id,
                'compare' => 'LIKE'
            )
        ));
    $res = query_posts($args);
    if(!empty($res)){
        return $res[0];
    }
    return null;
}

/* Performs the data migration.
 */
function nia_migrate(){
  display_options();
    
  global $nia_fields_array;
  $data = nia_get_data();
  $counter = 0;
    
  putenv("FREETDSCONF=/etc/freetds/freetds.conf");

  //Disable filter - see long comment below
  if(DONT_FILTER_POSTS){
    echo "Removing filters...";
    kses_remove_filters();
    echo "Done." . "<br>";
  }
        
  foreach((array)$data as $line){
    if(DEBUG_DATA){
      echo "Line " . $counter . ":" . "<br>";
    }
    $postarr = [];
    $meta_input = [];
    $tax_input = [];

    $baseurl = $line["BaseURL"];
    $filename = $line["FileName"];
    $imagecount = $line["ImageCount"];
    $endurl = $line["EndURL"];
    
    $post = nia_get_wp_post_from_lid($filename);

    if(is_null($post)){
        echo $filename . " has no matching WordPress post." . "<br>";
        continue;
    }

    //Construct the addition
    $images = "";
    
    //Update the post!
    $postarr["ID"] = $post->ID;
    $postarr["post_author"] = $post->post_author;
    $postarr["post_name"] = $post->post_name;
    $postarr["post_type"] = $post->post_type;
    $postarr["post_title"] = $post->post_title;
    $postarr["post_date"] = $post->post_date;
    $postarr["post_date_gmt"] = $post->post_date_gmt;
    
    $postarr["post_content"] = $post->post_content . $images;
    
    $postarr["post_excerpt"] = $post->post_excerpt;
    $postarr["post_status"] = $post->post_status;
    $postarr["comment_status"] = $post->comment_status;
    $postarr["ping_status"] = $post->ping_status;
    $postarr["post_status"] = $post->post_status;
    $postarr["post_parent"] = $post->post_parent;
    $postarr["post_modified"] = $post->post_modified;
    $postarr["post_modified_gmt"] = $post->post_modified_gmt;
    $postarr["comment_count"] = $post->comment_count;
    $postarr["menu_order"] = $post->menu_order;
    wp_update_post($postarr);
    echo "Updated " . $filename . "." . "<br>";


    if(DEBUG_DATA){ echo "<br>"; }
    $counter++;

    if($counter > 0){
      break;
    }
  }

  echo $counter . " records inserted." . "<br>";
    
  if(DONT_FILTER_POSTS){
    echo "Turning filters back on...";
    kses_init_filters();
    echo "Done." . "<br>";
  }
    
  echo "<br>Done.";
  exit();
}



/* We want to perform the migration only after a button has been pressed in the
 * backend. Everything below this comment sets that up.
 *
 * Taken from http://stackoverflow.com/a/33958029
 */

function nia_migrate_button_admin_page() {
  // This function creates the output for the admin page.
  // It also checks the value of the $_POST variable to see whether
  // there has been a form submission. 
    
  // The check_admin_referer is a WordPress function that does some security
  // checking and is recommended good practice.
    
  // General check for user permissions.
  if (!current_user_can('manage_options'))  {
    wp_die("You can't do this!");
  }
    
  // Start building the page
    
  echo '<div class="wrap">';
    
  echo '<h2>NIA Magazine Article Migration</h2>';
    
  // Check whether the button has been pressed AND also check the nonce
  if (isset($_POST['nia_migrate_button'])
      && check_admin_referer('nia_migrate_button_clicked')) {
    // the button has been pressed AND we've passed the security check
    nia_migrate();
  }
    
  echo '<form action="options-general.php?page=nia_migrate-button-slug"'
    . 'method="post">';
    
  // this is a WordPress security feature
  //see: https://codex.wordpress.org/WordPress_Nonces
  wp_nonce_field('nia_migrate_button_clicked');
  echo '<input type="hidden" value="true" name="nia_migrate_button" />';
  submit_button('Migrate!');
  echo '</form>';
    
  echo '</div>';
}

function nia_migrate_button_menu(){
  add_menu_page('Migrate Old NIA Magazine Articles',
                'NIA Migration',
                'manage_options',
                'nia_migrate-button-slug',
                'nia_migrate_button_admin_page');

}
add_action('admin_menu', 'nia_migrate_button_menu');

?>