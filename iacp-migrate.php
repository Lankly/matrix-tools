<?php
/*
  Plugin Name: IACP Data Migration
  Plugin URI:  http://github.com/Lankly/matrix-tools/tree/iacp-data-migration
  Description: Migrates all the issues of Police Chief Magazine to WordPress.
  Version:     0.1
  Author:      Matrix Group International
  Author URI:  http://matrixgroup.net/
  License:     GPL2
  License URI: https://www.gnu.org/licenses/gpl-2.0.html
  Text Domain: iacp-migration
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
class IACPField {
    public $orig_name; /* Field in the csv */
    public $new_name;  /* Field in the WP db */
    public $required;  /* Whether or not the field can be blank */
    public $is_cust_field; /* True if this is a custom field in WP */
    public $strip_html; /* True if this field should strip out all HTML tags */
    function __construct($orig_name,
                         $new_name,
                         $req,
                         $cust = false,
                         $html = false){
        $this->orig_name = $orig_name;
        $this->new_name = $new_name;
        $this->required = $req;
        $this->is_cust_field = $cust;
        $this->strip_html = $html;
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
$iacp_fields_array = [
    new IACPField("article_title", "post_title", FIELD_REQUIRED),
    new IACPField("article_text", "post_content", FIELD_REQUIRED),
    new IACPField("author", "post_author", FIELD_NOT_REQUIRED, true, true),
    new IACPField("issue_date", "post_date", FIELD_NOT_REQUIRED),
    new IACPField("legacy_article_id",
                  "legacy_article_id", FIELD_NOT_REQUIRED, true),
    new IACPField("legacy_issue_id",
                  "legacy_issue_id", FIELD_NOT_REQUIRED, true)
];


/* FUNCTIONS */

/* Small little function to generate the second argument for sqlsrv_connect().
 * It takes into account the options at the top of the file. Returns an array.
 */
function iacp_get_connection_info($creds){
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

/* Returns the query in 'query.txt' to be performed on the database in
 * 'credentials.txt'.
 *
 * Each row of the result should contain all the columns in $iacp_fields_array,
 * but only the required columns are necessary.
 */
function iacp_read_query(){
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
function iacp_read_credentials(){
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
 * trying to move. Each string is in the order of $iacp_fields_array.
 */
function iacp_get_data(){
    global $iacp_fields_array;
    $creds = iacp_read_credentials();
    
    //Create the connection information array, taking into account options
    $connectionInfo = iacp_get_connection_info($creds);

    // Actually create connection
    echo "Connecting to " . $creds["server"] . "... ";
    $conn = mssql_connect($creds["server"],
                          $creds["username"],
                          $creds["password"])
          or wp_die("Failed.");
    mssql_select_db($creds["database"], $conn );
    echo "Connection opened." . "<br>" . "<br>";

    //Perform the query
    $query = iacp_read_query();
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
         * outlined in the iacp_fields_array.
         */
        foreach((array)$iacp_fields_array as $field){
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
                else{
                    $value = "N/A";
                }
            }

            //Should we remove the HTML from this field?
            if($field->strip_html){
                $value = strip_tags($value);
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

/* Performs the data migration.
 */
function iacp_migrate(){
    display_options();
    
    global $iacp_fields_array;
    $data = iacp_get_data();
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

        foreach((array)$line as $index => $field){
            if(DEBUG_DATA){
                echo $field . "<br>";
            }

            $field = mb_convert_encoding($field, 'UTF-8', 'UTF-8');

            //You need a name for each field for this method
            $wp_name = $iacp_fields_array[$index]->new_name;

            //Custom fields need to go in their own array
            if($iacp_fields_array[$index]->is_cust_field){
                $meta_input[$wp_name] = $field;
            }
            else{
                $postarr[$wp_name] = $field;
            }
        }

        //Insert the post!
        $postarr["meta_input"] = $meta_input;
        if(DEBUG_INSERT){
            /* Important to note that if the value doesn't show up, it's because
             * htmlSpecialChars returns empty if it's given an invalid html code
             * unit sequence.
             *
             * WordPress sanitizes everything before putting it in the database,
             * and if the post_content string after that sanitation is emtpy, it
             * simply won't insert that post (I think).
             *
             * So, if the post_content value doesn't show up, that post won't be
             * created.
             *
             * I have found a workaround that disables the filter before you try
             * to insert a post. This means that the insert could potentially be
             * dangerous to the site. If you want this filter removed, go to the
             * top and set the "DONT_FILTER_POSTS" option to true.
             *
             * As another solution to this problem, you can turn on this option:
             * USE_UTF_8_CONVERSION
             */
            foreach((array)$postarr as $key => $val){
                if(strcmp($key, "meta_input") == 0){
                    foreach((array)$val as $k => $v){
                        echo "<br>"
                            . $k
                            . ": "
                            . htmlspecialchars($v);
                    }
                }
                else{
                    echo "<br>"
                        . $key
                        . ": "
                        . htmlspecialchars($val);
                }
            }
        }
        $post_id = wp_insert_post($postarr);

        //Publish the post, if desired
        if(IMPORT_PUBLISHED && $post_id > 0){
            wp_publish_post($post_id);
        }

        if(DEBUG_DATA){ echo "<br>"; }
        $counter++;
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



/* We want to perform the migration only  after a button has been pressed in the
 * backend. Everything below this comment sets that up.
 *
 * Taken from http://stackoverflow.com/a/33958029
 */

function iacp_migrate_button_admin_page() {
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
    
    echo '<h2>IACP Magazine Article Migration</h2>';
    
    // Check whether the button has been pressed AND also check the nonce
    if (isset($_POST['iacp_migrate_button'])
        && check_admin_referer('iacp_migrate_button_clicked')) {
        // the button has been pressed AND we've passed the security check
        iacp_migrate();
    }
    
    echo '<form action="options-general.php?page=iacp_migrate-button-slug"'
        . 'method="post">';
    
    // this is a WordPress security feature
    //see: https://codex.wordpress.org/WordPress_Nonces
    wp_nonce_field('iacp_migrate_button_clicked');
    echo '<input type="hidden" value="true" name="iacp_migrate_button" />';
    submit_button('Migrate!');
    echo '</form>';
    
    echo '</div>';
}

function iacp_migrate_button_menu(){
    add_menu_page('Migrate Old IACP Magazine Articles',
                  'IACP Migration',
                  'manage_options',
                  'iacp_migrate-button-slug',
                  'iacp_migrate_button_admin_page');

}
add_action('admin_menu', 'iacp_migrate_button_menu');

?>