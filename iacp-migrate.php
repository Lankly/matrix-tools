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
define("DEBUG", true);           /* Set to false to disable all other DEBUGs. */
define("DEBUG_CREDS", DEBUG && false);        /* Echo credentials information */
define("DEBUG_DATA", DEBUG && false);         /* Echo all exported data. */
define("DEBUG_FIRST_LINE", DEBUG && false);   /* Echo first line information. */
define("DEBUG_QUERY", DEBUG && true);         /* Echo query information. */
define("USE_CONNECTION_POOLING", false); /* Make a new connection every time? */
define("USE_WINDOWS_AUTH", true);       /* Use Windows Auth to connect to db? */

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
define("FIELD_REQUIRED", true);
define("FIELD_NOT_REQUIRED", false);
define("COLUMN_NOT_FOUND", -1);

/* This class is basically just a struct for holding information about field
 * names and where they are.
 */
class IACPField {
    public $orig_name; /*Field in the csv*/
    public $new_name;  /*Field in the WP db*/
    public $required;  /*Whether or not the field can be blank*/
    public $col_num;   /*Which column in the data is this field*/
    function __construct($orig_name, $new_name, $req){
        $this->orig_name = $orig_name;
        $this->new_name = $new_name;
        $this->required = $req;
        $this->col_num = COLUMN_NOT_FOUND;
    }
}

/* Every field in this array is one that will be mapped to a field in the new
 * database. If any of the fields still have the default value after the file
 * has been read, the program should fail.
 */
$iacp_fields_array = [
    new IACPField("article_title", "", FIELD_REQUIRED),
    new IACPField("article_text", "", FIELD_REQUIRED),
    new IACPField("issue_month", "", FIELD_REQUIRED),
    new IACPField("issue_year", "", FIELD_REQUIRED),
    new IACPField("issue_description", "", FIELD_REQUIRED),
    new IACPField("volume", "", FIELD_REQUIRED),
    new IACPField("issue_id", "", FIELD_REQUIRED),
    new IACPField("cover_filename", "", FIELD_REQUIRED),
];

/* Handles all of reading a given first line of a csv file to figure out where
 * each of our fields are in order.
 */
function iacp_read_first_line(string $fl){
    if(empty($fl)){
        die("First line is empty!");
    }

    if(DEBUG_FIRST_LINE){
        echo "Raw first line: " . $fl . PHP_EOL;
    }
    
    //Look at each individual element of the csv
    foreach (explode(",", $fl) as $index => $fieldname){
        //Loop through the array of fields
        foreach((array)$iacp_fields_array as $field){
            
            if(DEBUG_FIRST_LINE){
                echo "Field " . $index . ": " . $fieldname . PHP_EOL;
            }
            
            //And if it's there, set it
            if(strcmp(strtolower(trim($field->orig_name)),
                      strtolower(trim($fieldname))) == 0){
                $field->col_num = $index;
                
                break;
            }
        }
    }

    //Now make sure every required field was there
    foreach((array)$iacp_fields_array as $field){
        if($field->col_num == FIELD_REQUIRED){
            die("Didn't find " . $field.orig_name . ".");
        }
    }
}

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
    $f = fopen($filename, "r") or die("Unable to open file!");

    if(DEBUG_CREDS){echo "Beginning read..." . PHP_EOL;}

    while(!empty($line = fgets($f))){
        //No need for a PHP_EOL on this echo since $line will contain a newline
        if(DEBUG_CREDS){
            echo substr($line, 0, strlen($line) - 2);
        }
        
        $index = strpos($line, ":");
        $field = substr($line, 0, $index);
        $to_return[$field] = trim(substr($line, $index + 1));
        
        if(DEBUG_CREDS){
            echo " ("
                . $to_return[$field] . ")"
                . PHP_EOL;
        }
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

/* We're going to grab the first csv file in this directory and break it up into
 * something we can use. This function is separate so that if we want to turn it
 * into something that performs operations directly on a csv file, we won't have
 * to change any other functions.
 *
 * Returns an array of arrays of strings that represent all the data we are
 * trying to move.
 */
function iacp_get_data(){
    $to_return = [];
    $creds = iacp_read_credentials();

    //Create the connection information array, taking into account options
    $connectionInfo = iacp_get_connection_info($creds);

    // Actually create connection
    echo "Connecting to " . $creds["server"] . "... ";
    $conn = sqlsrv_connect($creds["server"], $connectionInfo) or die("Failed.");
    echo "Success." . PHP_EOL;

    

    // Close connection when done.
    if(!empty($conn)){
        echo "Closing connection...  ";
        sqlsrv_close($conn);
    }
    echo "Connection Closed." . PHP_EOL . PHP_EOL;
    
    return $to_return;
}

function iacp_migrate(){
    $data = iacp_get_data();
    $counter = 1;
    
    foreach((array)$data as $line){
        if(DEBUG_DATA){
            echo "Line " . $counter . ":" . PHP_EOL;
        }
        foreach((array)$line as $field){
            if(DEBUG_DATA){
                echo $field . PHP_EOL;
            }
        }
        $counter++;
    }
}

iacp_migrate();

echo "Done.";

exit;

?>