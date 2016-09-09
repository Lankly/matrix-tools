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

/* OPTIONS */
/* These are all called "Debug" not because they do any debugging on their own,
 * but rather because you should use them while debugging.
 */
define("DEBUG", true);           /* Set to false to disable all other DEBUGs. */
define("DEBUG_CREDS", DEBUG && false);        /* Echo credentials information */
define("DEBUG_DATA", DEBUG && false);         /* Echo all exported data. */
define("DEBUG_FIRST_LINE", DEBUG && false);   /* Echo first line information. */
define("DEBUG_QUERY", DEBUG && false);        /* Echo complete query. */
define("DEBUG_QUERY_RESULTS", DEBUG && false);/* Echo the query results. */
define("USE_CONNECTION_POOLING", false); /* Make a new connection every time? */
define("USE_WINDOWS_AUTH", false);      /* Use Windows Auth to connect to db? */

/* Tell the user what options are in use. */
if(DEBUG_CREDS){
    echo "Credentials debugging enabled." . PHP_EOL;
}
if(DEBUG_DATA){
    echo "Data debugging enabled." . PHP_EOL;
}
if(DEBUG_FIRST_LINE){
    echo "First-line debugging enabled." . PHP_EOL;
}
if(DEBUG_CREDS || DEBUG_DATA || DEBUG_FIRST_LINE){
    echo PHP_EOL;
}
if(USE_CONNECTION_POOLING){
    echo "Connection Pooling for database connection enabled." . PHP_EOL;
}
if(USE_WINDOWS_AUTH){
    echo "Windows Authorization for database connection enabled." . PHP_EOL;
}
if(USE_CONNECTION_POOLING || USE_WINDOWS_AUTH){
    echo PHP_EOL;
}

/* CONSTANTS */
define("DEBUG_LINE_WIDTH", 80);
define("FIELD_NOT_REQUIRED", false);
define("FIELD_REQUIRED", true);

/* This class is basically just a struct for holding information about field
 * names and where they are.
 */
class IACPField {
    public $is_cust_field; /* True if this is a custom field in WP */
    public $new_name;  /* Field in the WP db */
    public $orig_name; /* Field in the csv */
    public $required;  /* Whether or not the field can be blank */
    function __construct($orig_name, $new_name, $req, $cust = false){
        $this->orig_name = $orig_name;
        $this->new_name = $new_name;
        $this->required = $req;
        $this->is_cust_field = $cust;
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
    new IACPField("article_id", "ID", FIELD_REQUIRED),
    new IACPField("article_title", "post_title", FIELD_NOT_REQUIRED),
    new IACPField("article_text", "post_content", FIELD_NOT_REQUIRED),
    new IACPField("author", "post_author", FIELD_NOT_REQUIRED, true),
    new IACPField("issue_date", "post_date", FIELD_NOT_REQUIRED)
];

/* FUNCTIONS */

/* This function should only ever be called after iacp_read_first_line. It
 * expects the col_num field of the 
 */
function iacp_format_line($line){
    if(!$line){
        return ["Empty line."];
    }

    //Put each field into its correct place in the new array
    $fields = explode(",", $line);
    $to_return = [];
    foreach((array)$iacp_fields_array as $i){
        if($i->COLUMN_NOT_FOUND){
            continue;
        }
        
        $tmp = $fields[$i->col_num];

        //If this field was empty, check to see if that was okay
        if(($tmp == "" || $tmp == null) && $i->required){
            die("Required field " . $i->orig_name . " could not be found!");
        }

        $to_return[] = $tmp;
    }

    return $to_return;
}

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
    $filename = "query.txt";
    $f = fopen($filename, "r") or die("Unable to read query file!");
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
    
    $filename = "credentials.txt";
    $f = fopen($filename, "r") or die("Unable to read credentials file!");

    if(DEBUG_CREDS){echo "Beginning read..." . PHP_EOL;}

    //Run through the file and put each matching line into $to_return
    while(!empty($line = fgets($f))){
        //No need for a PHP_EOL on this echo since $line will contain a newline
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
                . PHP_EOL;
        }
    }

    //Don't forget to close the file!
    if($f){
        fclose($f);
    }
    
    //If any of the fields wasn't found, fail
    foreach((array)$to_return as $key => $field){
        if(empty($field)){
            die("Missing " . $key . "!" . PHP_EOL);
        }
    }
    
    if(DEBUG_CREDS){
        echo "Done read." . PHP_EOL . PHP_EOL;
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
    $conn = sqlsrv_connect($creds["server"], $connectionInfo) or die("Failed.");
    echo "Connection opened." . PHP_EOL . PHP_EOL;

    //Perform the query
    $query = iacp_read_query();
    if(DEBUG_QUERY){ echo "Query: " . PHP_EOL . $query  . PHP_EOL;}
    $resp = sqlsrv_query($conn, $query, []) or die("Query failed!");

    //Process the result
    $to_return = [];
    while($obj = sqlsrv_fetch_object( $resp )){
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
            $value = $obj->$prop_name;
            $line[] = $value;

            //Check for missing data from required field.
            if($field->required == FIELD_REQUIRED && empty($value)){
                echo "Missing required field! Skipping line.";
                $all_required_fields_accounted_for = false;
                break;
            }
            
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
        if(DEBUG_QUERY_RESULTS){ echo PHP_EOL . PHP_EOL; }
    }

    echo count($to_return) . " records imported." . PHP_EOL . PHP_EOL;
    
    // Close connection when done.
    if(!empty($conn)){
        echo "Closing connection...  ";
        sqlsrv_close($conn);
    }
    echo "Connection Closed." . PHP_EOL . PHP_EOL;
    
    return $to_return;
}

/* Performs the data migration.
 */
function iacp_migrate(){
    global $iacp_fields_array;
    $data = iacp_get_data();
    $counter = 1;
    
    foreach((array)$data as $line){
        if(DEBUG_DATA){
            echo "Line " . $counter . ":" . PHP_EOL;
        }
        $postarr = [];
        $meta_input = [];

        foreach((array)$line as $index => $field){
            if(DEBUG_DATA){
                echo $field . PHP_EOL;
            }

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
        wp_insert_post($postarr);

        if(DEBUG_DATA){ echo PHP_EOL; }
        $counter++;
    }
}

iacp_migrate();

echo "Done.";

exit;

?>