<?php
    
  // load config
  require_once( 'config.php' );

  // connect to email_scraper db
  $Db = new mysqli( EMAIL_SCRAPER_HOST, EMAIL_SCRAPER_USER, EMAIL_SCRAPER_PASS, EMAIL_SCRAPER_NAME );


  // --- get next target from sqs, or populate the queue if empty -----------------
  

  // get next target to crawl
  require_once( 'Amazon_sqs.php' );
  
  $Sqs = new Amazon_sqs();
  $target = $Sqs->get_message();
  
  // target was returned
  if( $target ) {
  
    // update company in master db
		$sql = "UPDATE websites
		        SET
		          queued_for_processing = 1,
		          last_queued_for_processing = NOW()
		        WHERE id = ".$target['id'];
    $Query = $Db->query( $sql );
  
  // queue was empty, so try to populate it
  } else {
  
    // load targets from database
    //
    //  - skip targets that have already been crawled ( already have emails in the emails table )
    //  - these either completed or broke midway
    //  - this allows us to kill a crawler server and it'll start back up where it left off
    //    - it will lose the last company it was processing only if those emails started writing to the db
    //
    $sql = "SELECT id, url
            FROM websites
            WHERE
              queued_for_processing = 0
              AND id NOT IN(
                SELECT distinct website_id
                FROM emails
              )
            LIMIT 1000";
    $Query = $Db->query( $sql );
    if( $Query ) {
      while( $Row = $Query->fetch_object() ) {
        $target['id'] = $Row->id;
        $url = parse_url( $Row->url, PHP_URL_SCHEME ).'://'.parse_url( $Row->url, PHP_URL_HOST );
        $target['url'] = trim( $url, '"' );
        $targets[] = $target;
      }
    }
    if( $Sqs->populate_queue( $targets ) )
      die( 'Website Extractor SQS Queue populated. No website was processed.' );
    else
      die( 'Website Extractor SQS Queue failed to populate. No website was processed.' );
    
  }
  
  var_dump( $target );
  
  die;
  // --- process the target -------------------------------------------------------
  
  
  // populate website object
  require_once( 'Website.php' );
  $Website = new Website();
  $Website->id = $target['id'];
  $Website->base_url = $target['url'];
  
  // crawl website
  require( 'Crawler.php' );
  $Crawler = new Crawler( $Website );
  $Crawler->go( CRAWLER_MAX_WEBPAGES_TO_CRAWL );
  
  // compile data from all webpages crawled on this website and write to db
  echo "\n\n -- Saving Emails to `email_scraper` Database";
  $webpages = $Website->get_webpages();
  foreach( $webpages as $Webpage ) {
    if( count( $Webpage->emails ) > 0 ) {
      $emails = array_count_values( $Webpage->emails );
      foreach( $emails as $email => $count ) {
        echo "\n  - $email ($count)";
        $sql = "INSERT INTO emails( website_id, url, email, count )
                VALUES( ".$Website->id.", '".$Db->real_escape_string( $Webpage->url )."', '".$Db->real_escape_string( $email )."', $count )";
        $Db->query( $sql );
      }
    }
  }
  
  
  // --- delete message as target was succesfully processed -----------------------
  
  
  // delete message
  $Sqs->delete_message();
    
  // update website as not processing in the db
	$sql = "UPDATE websites
	        SET queued_for_processing = 0
	        WHERE id = ".$Website->id;
  $Query = $Db->query( $sql );

?>