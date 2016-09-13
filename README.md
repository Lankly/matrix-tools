# IACP Data Migration

This tool was created to move data from a MSSQL server to WordPress posts for IACP. It is very easily modified to do the same for another MSSQL server.

<h2>Table of Contents</h2>
* [Setting it up](#setting-it-up)
* [Running the Migration](#running-the-migration)
* [Options](#options)
  * [Normal Options](#normal-options)
  * [Debugging Options](#debugging-options)

#Setting it up

1. In order to use this, you'll need [FreeTDS](http://www.freetds.org/) installed on the server that hosts your WordPress site.
2. Clone this repo.
    * `git clone -b iacp-data-migration https://github.com/Lankly/matrix-tools.git iacp-data-migration`
3. Edit the credentials file to include all the information you need. The name of the server should be the same as the one set up for FreeTDS.
4. Edit the query file to include the SQL query that will be executed to get your data.
5. Edit the php file as follows:
    * <p>Add all the columns returned by your query to the `$iacp_fields_array`. Every field in this array is one that will be mapped to a field in the new database.</p>
    <p>If any of the fields still have the default value after the file has been read, the program should fail. The first parameter is the name of a column from the query we're exporting from. The second is that same field in the database we're importing to. If you want two fields to become one, change the query to combine them first.</p>
    <p>Please see the documentation for [`wp_insert_post()`](https://developer.wordpress.org/reference/functions/wp_insert_post/). The second field should be one of the names under `$postarr` or the name of a custom field. If it is a custom field, "true" should be passed as a fourth parameter.</p>
    * Examine the options at the top of the file and turn on the ones you want to use. (See the [Options](#options) section below)
6. Zip the entire folder and submit it to your WordPress site as a plugin, then enable it.

#Running the Migration

This part is really simple. The plugin creates a button in the side menu called "IACP Migration". Click on it and you'll be taken to a page with a button on it that reads "Migrate!". Click that button and wait for it to finish. You'll be presented with which options are turned on, any debugging info you've asked for, and some other information about what happened during the migration, telling you how many records were imported.

#Options
Options are true/false constants that by setting to true cause some behavior in the script to change.

##Normal Options

```php
DONT_FILTER_POSTS:
```
<b>On</b> by default.<br>
Normally, WordPress sanitizes all the fields that you enter into the post. Set this to true to disable this while the records are being created.

```php
USE_CONNECTION_POOLING:
```
<b>Off</b> by default.<br>
This was left in aftere switching to FreeTDS. Turning it on would allow `sqlsrv_connect()` to use a previously-established connection.

```php
USE_UTF_8_CONVERSTION:
```
<b>On</b> by default.<br>
This will guarantee that each field is correctly formatted for UTF-8, which may help it make sure that WordPress will actually insert the post.

```php
USE_WINDOWS_AUTH:
```
<b>Off</b> by default.<br>
This was left in after switching to FreeTDS. While I was testing this script (before integreting with WordPress's API), I found myself needing to make sure that the reason it wasn't connecting to the database was not that the credentials were wrong. `sqlsrv_connect()`, the function I was using at the time, would automatically connect to the database with your windows credentials if you left out the username and password, so that's what this flag does.

##Debugging Options
These are used to help you debug what's not working.
```php
DEBUG:
```
<b>On</b> by default.<br>
This needs to be on for all other debugging options to work.

```php
DEBUG_CREDS:
```
<b>Off</b> by default.<br>
Prints out each of the credentials as they are read in.


```php
DEBUG_DATA:
```
<b>Off</b> by default.<br>
Prints out all the data retrieved from the query as it's read in.

```php
DEBUG_FIRST_LINE:
```
<b>Off</b> by default.<br>
This was left in after the switch to querying a database (no longer using csv file). What it would do was print out the first line, which would tell us which column was which. If you can find another use for it, go ahead and use this flag.

```php
DEBUG_INSERT:
```
<b>Off</b> by default.<br>
Prints out all the information from each post right before that post is inserted.

```php
DEBUG_QUERY:
```
<b>Off</b> by default<br>
Prints out the query as it's read in from the query file.

```php
DEBUG_QUERY_RESULTS:
```
<b>Off</b> by default<br>
Prints out the results of the query as they are read in, row by row.
