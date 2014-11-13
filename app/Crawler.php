<?php

  class Crawler {
    
    private $Curl;
    private $Scraper;
    private $Website; // the website being crawled
    
    // array containing all found page urls
    private $urls = array();
    
    // construct
    public function __construct( website $Website ) {
  
      // store the website object
      $this->Website = $Website;
      
      // prime urls with base_url from website
      require_once( BASE_PATH.'app/models/Webpage.php' );
      $this->Website->add_webpage( new Webpage( $this->Website->base_url ) );
      
      // create curl object to download urls
      require_once( BASE_PATH.'app/Curl.php' );
      $this->Curl = new Curl();
      
      // creaste scraper object to process html
      require_once( BASE_PATH.'app/Html_scraper.php' );
      $this->Scraper = new Html_scraper();
      
    } // end function
    
    // crawl the site
    public function go(
      $limit = 1 // max files to crawl
    ) {
      
      $num_webpages_crawled = 0;
      $num_webpages_scraped = 0;
      $num_webpages_to_crawl = count( $this->Website->get_webpages() );
      while( $num_webpages_crawled < $num_webpages_to_crawl ) {
        
        // stop crawling when specified limit is reached
        if( $num_webpages_scraped == $limit ) {
          echo "\n\n ** Crawler processed specified max number of files .. crawling stopped.";
          break( 1 );
        }
        
        $webpages = $this->Website->get_webpages();
        $webpages = array_slice( $webpages, $num_webpages_crawled );
        $Webpage = current( $webpages );
        echo "\n\n=== PROCESSING WEBPAGE $num_webpages_crawled of $num_webpages_to_crawl Crawled - $num_webpages_scraped Scraped ( max to scrape: $limit )\n    $Webpage->url";
        if( $Webpage = $this->Curl->go( $Webpage ) ) {
          if( $Webpage->junk ) {
            echo "\n ** JUNK: $Webpage->download_error";
          } else {
            // scrape webpage
            $Webpage = $this->Scraper->go( $Webpage );
            // add new webpages to website
            $local_links = $Webpage->local_links;
            $local_links = array_unique( $local_links ); // do not iterate over duplicates
            foreach( $local_links as $url )
              $this->Website->add_webpage( new Webpage( $url ) );
            $num_webpages_scraped++;
          }
        }
        
        // update for next loop
        $this->Website->update_webpage( $Webpage );
        $num_webpages_crawled++;
        $num_webpages_to_crawl = count( $this->Website->get_webpages() );
        usleep( CRAWLER_SLEEP_BETWEEN_DOWNLOADS );
                
      }
      
    } // end function
    
  } // end class

?>