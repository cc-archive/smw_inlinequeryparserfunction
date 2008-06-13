<?php //{{MediaWikiExtension}}<source lang="php">
/*
 * SMW_InlineQueryParserFunction.php - Adds a parser function {{#ask}}, equivalent to <ask>
 * @author Jim R. Wilson
 * @version 0.1
 * @copyright Copyright (C) 2007 Jim R. Wilson
 * @license The MIT License - http://www.opensource.org/licenses/mit-license.php 
 * -----------------------------------------------------------------------
 * Description:
 *     This is a MediaWiki extension which adds a parser function for performing
 *     Semantic MediaWiki inline queries using a parser function.
 * Requirements:
 *     MediaWiki 1.6.x, 1.9.x, 1.10.x or higher
 *     PHP 4.x, 5.x or higher.
 * Installation:
 *     1. Drop this script (SMW_InlineQueryParserFunction.php) into the directory $IP/extensions
 *         Note: $IP is your MediaWiki install dir.
 *     2. Enable the extension by adding this line to your LocalSettings.php:
 *         require_once('extensions/SMW_InlineQueryParserFunction.php');
 *         Note: Make sure this appears later in LocalSettings than the include_once which enables
 *         Semantic MediaWiki itself!
 * Version Notes:
 *     version 0.1:
 *         Initial release.
 * -----------------------------------------------------------------------
 * Copyright (c) 2007 Jim R. Wilson
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy 
 * of this software and associated documentation files (the "Software"), to deal 
 * in the Software without restriction, including without limitation the rights to 
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of 
 * the Software, and to permit persons to whom the Software is furnished to do 
 * so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all 
 * copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, 
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES 
 * OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND 
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT 
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, 
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING 
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR 
 * OTHER DEALINGS IN THE SOFTWARE. 
 * -----------------------------------------------------------------------
 */
 
# Confirm MW environment
if (defined('MEDIAWIKI')) {

# Credits
$wgExtensionCredits['parserhook'][] = array(
    'name'=>'SMW_InlineQueryParserFunction',
    'author'=>'Jim R. Wilson - wilson.jim.r&lt;at&gt;gmail.com',
    'url'=>'http://jimbojw.com/wiki/index.php?title=SMW_InlineQueryParserFunction',
    'description'=>'Adds a parser function (#ask) equivalent to the extension tag &lt;ask&gt;',
    'version'=>'0.1'
);


/**
 * Wrapper class for encapsulating SMWIQPF related parser methods
 */
class SMWInlineQueryParserFunction {

    /**
     * Performs regular expression search or replacement.
     * @param Parser $parser Instance of running Parser.
     * @return String Result of executing query.
     */
    function parserFunction( &$parser ) {
        $params = func_get_args();
        array_shift( $params );
        $query = '';
        $args = array();
        foreach ($params as $key=>$param) {
            if (strpos($param,'=')===false) {
                $param = "query=$param";
            }
            list($name, $val) = split('=',$param,2);
            $name = strtolower(trim($name));
            $args[$name] = trim($val);
            if ($name=='query') $query .= $val;
        }
        return smwfProcessInlineParserFunctionQueries($query, $args);
    }
    
    /**
     * Adds magic words for parser functions
     * @param Array $magicWords
     * @param $langCode
     * @return Boolean Always true
     */
    function parserFunctionMagic( &$magicWords, $langCode ) {
        $magicWords['ask'] = array( 0, 'ask' );
        return true;
    }
    
    /**
     * Sets up parser functions
     */
    function parserFunctionSetup( ) {
        global $wgParser;
        $wgParser->setFunctionHook( 'ask', array($this, 'parserFunction') );
    }
    
}

# Create global instance and wire it up
$wgSMWInlineQueryParserFunction = new SMWInlineQueryParserFunction();
$wgHooks['LanguageGetMagic'][] = array($wgSMWInlineQueryParserFunction, 'parserFunctionMagic');
$wgExtensionFunctions[] = array($wgSMWInlineQueryParserFunction, 'parserFunctionSetup');

######################## SUPPORTING CODE ###########################

/**
 * Everything that is between <ask> and </ask> gets processed here
 * $text is the query in the query format described elsewhere
 * $param is an array which might contain values for various parameters
 * (see SMWInlineQuery).
 */
function smwfProcessInlineParserFunctionQueries( $text, $param ) {
    global $smwgIQEnabled;
    $iq = new SMWWikiInlineQuery($param);
    if ($smwgIQEnabled) {
        return $iq->getWikiResult($text);
    } else {
        return wfMsgForContent('smw_iq_disabled');
    }
}

/**
 * Slight variant on SMWInlineQuery class - just for handling wiki syntax requests
 */
class SMWWikiInlineQuery {

    private $mInline; // is this really an inline query, i.e. are results used in an article or not? (bool)

    // parameters:
    private $mParameters; // full parameter list for later reference
    private $mLimit; // max number of answers, also used when printing values of one particular subject
    private $mOffset; // number of first returned answer
    private $mSort;  // supplied name of the row by which to sort
    private $mSortkey;  // db key version of the row name by which to sort
    private $mOrder; // string that identifies sort order, 'ASC' (default) or 'DESC'
    private $mFormat;  // a string identifier describing a valid format
    private $mIntro; // text to print before the output in case it is *not* empty
    private $mSearchlabel; // text to use for link to further results, or empty if link should not be shown
    private $mLinkSubj; // should article names of the (unique) subjects be linked?
    private $mLinkObj; // should article names of the objects be linked?
    private $mDefault; // default return value for empty queries
    private $mShowHeaders; // should the headers (property names) be printed?
    private $mMainLabel; // label used for displaying the subject, or NULL if none was given
    private $mShowDebug; // should debug output be generated?

    // fields used during query processing:
    private $mQueryText; // the original query text for future reference
    private $dbr; // pointer to the database used throughout exectution of the query
    private $mRename; // integer counter to rename tables in SQL joins
    private $mSubQueries; // array of subqueries, indexed by placeholder indices
    private $mSQCount; // integer counter to replace subqueries
    private $mConditionCount; // count the number of conditions used so far
    private $mTableCount; // count the number of tables joined so far
    private $mPrintoutCount; // count the number of fields selected for separate printout so far
    private $mFurtherResults=false; // true if not all results to the query were shown
    private $mDisplayCount=0; // number of results that were displayed
    private $mQueryResult; // retrieved query result

    // other stuff
    private $mLinker; // we make our own linker for creating the links -- TODO: is this bad?

    public function SMWWikiInlineQuery($param = array(), $inline = true) {
        global $smwgIQDefaultLimit, $smwgIQDefaultLinking;

        $this->mInline = $inline;

        $this->mLimit = $smwgIQDefaultLimit;
        $this->mOffset = 0;
        $this->mSort = NULL;
        $this->mSortkey = NULL;
        $this->mOrder = 'ASC';
        $this->mFormat = 'auto';
        $this->mIntro = '';
        $this->mSearchlabel = NULL; //indicates that printer default should be used
        $this->mLinkSubj = ($smwgIQDefaultLinking != 'none');
        $this->mLinkObj = ($smwgIQDefaultLinking == 'all');
        $this->mDefault = '';
        $this->mShowHeaders = true;
        $this->mMainLabel = NULL;
        $this->mShowDebug = false;

        $this->mLinker = new Linker();

        $this->setParameters($param);
    }

    /**
     * Set the internal settings according to an array of parameter values.
     */
    private function setParameters($param) {
        global $smwgIQMaxLimit, $smwgIQMaxInlineLimit;
        $this->mParameters = $param;
        
        if ($this->mInline) 
            $maxlimit = $smwgIQMaxInlineLimit;
        else $maxlimit = $smwgIQMaxLimit;

        if ( !$this->mInline && (array_key_exists('offset',$param)) && (is_int($param['offset'] + 0)) ) {
            $this->mOffset = min($maxlimit - 1, max(0,$param['offset'] + 0)); //select integer between 0 and maximal limit -1
        }
        // set limit small enough to stay in range with chosen offset
        // it makes sense to have limit=0 in order to only show the link to the search special
        if ( (array_key_exists('limit',$param)) && (is_int($param['limit'] + 0)) ) {
            $this->mLimit = min($maxlimit - $this->mOffset, max(0,$param['limit'] + 0));
        }
        if (array_key_exists('sort', $param)) {
            $this->mSort = $param['sort'];
            $this->mSortkey = smwfNormalTitleDBKey($param['sort']);
        }
        if (array_key_exists('order', $param)) {
            if (('descending'==strtolower($param['order']))||('reverse'==strtolower($param['order']))||('desc'==strtolower($param['order']))) {
                $this->mOrder = "DESC";
            }
        }
        if (array_key_exists('format', $param)) {
            $this->mFormat = strtolower($param['format']);
            if (($this->mFormat != 'ul') && ($this->mFormat != 'ol') && ($this->mFormat != 'list') && ($this->mFormat != 'table') && ($this->mFormat != 'broadtable') && ($this->mFormat != 'timeline'))
                $this->mFormat = 'auto'; // If it is an unknown format, default to list again
        }
        if (array_key_exists('intro', $param)) {
            $this->mIntro = htmlspecialchars(str_replace('_', ' ', $param['intro']));
        }
        if (array_key_exists('searchlabel', $param)) {
            $this->mSearchlabel = htmlspecialchars($param['searchlabel']);
        }
        if (array_key_exists('link', $param)) {
            switch (strtolower($param['link'])) {
            case 'head': case 'subject':
                $this->mLinkSubj = true;
                $this->mLinkObj  = false;
                break;
            case 'all':
                $this->mLinkSubj = true;
                $this->mLinkObj  = true;
                break;
            case 'none':
                $this->mLinkSubj = false;
                $this->mLinkObj  = false;
                break;
            }
        }
        if (array_key_exists('default', $param)) {
            $this->mDefault = htmlspecialchars(str_replace('_', ' ', $param['default']));
        }
        if (array_key_exists('headers', $param)) {
            if ( 'hide' == strtolower($param['headers'])) {
                $this->mShowHeaders = false;
            } else {
                $this->mShowHeaders = true;
            }
        }
        if (array_key_exists('mainlabel', $param)) {
            $this->mMainLabel = htmlspecialchars($param['mainlabel']);
        }
        if (array_key_exists('debug', $param)) {
            $this->mShowDebug = true;
        }
    }

    /**
     * Returns true if a query was executed and the chosen limit did not
     * allow all results to be displayed
     */
    public function hasFurtherResults() {
        return $this->mFurtherResults;
    }

    /**
     * After a query was executed, this function returns the number of results that have been
     * displayed (which is different from the overall number of results that exist).
     */
    public function getDisplayCount() {
        return $this->mDisplayCount;
    }

    /**
     * Returns all parameters in their original array form. Useful for printer objects that
     * want to introduce their own parameters.
     */
    public function getParameters() {
        return $this->mParameters;
    }

    /**
     * Return the name of the format that is to be used for printing the query.
     */
    public function getFormat() {
        return $this->mFormat;
    }
    
    /**
     * Return the introduction string for printing the query.
     */
    public function getIntro() {
        return $this->mIntro;
    }

    /**
     * Return the default string for printing of the query returns no results.
     */
    public function getDefault() {
        return $this->mDefault;
    }

    /**
     * Return the label that should be used for the link to the search for
     * more results. If '' (empty string) is returned, the link should not be
     * shown. If NULL is returned, the default of the printer should apply.
     */
    public function getSearchlabel() {
        return $this->mSearchlabel;
    }    

    /**
     * Returns a boolean stating whether or not headers should be displayed
     */
    public function showHeaders() {
        return $this->mShowHeaders;
    }

    /**
     * Returns a boolean stating whether the query was executed inline.
     */
    public function isInline() {
        return $this->mInline;
    }

    /**
     * Returns the URL of the ask special page, with parameters corresponding to the
     * current query.
     */
    public function getQueryURL() {
        $title = Title::makeTitle(NS_SPECIAL, 'ask');
        return $title->getLocalURL('query=' . urlencode($this->mQueryText) . '&sort=' . urlencode($this->mSort) . '&order=' . urlencode($this->mOrder));
    }

    /**
     * During execution of a query, this method is used to fetch result rows one by
     * one. FALSE will be returned if no more rows that should be printed remain.
     */
    public function getNextRow() {
        $this->mDisplayCount++;
        $row = $this->dbr->fetchRow( $this->mQueryResult );
        if ( (!$row) || ($this->mDisplayCount > $this->mLimit) ) {
            if ($row) $this->mFurtherResults = true; // there are more result than displayed
            return false;
        } else return $row;
    }

    /**
     * During execution of a query, this method is used create an appropriate iterator
     * object that encapsulates the values that are to be printed in a certain row and
     * column.
     */
    public function getIterator($print_data, $row, $subject) {
        global $smwgIQSortingEnabled;
        $sql_params = array('LIMIT' => $this->mLimit);
        $linked = ( ($this->mLinkObj) || (($this->mLinkSubj) && ($subject)) );

        switch ($print_data[1]) {
            case SMW_IQ_PRINT_CATS:
                if ($smwgIQSortingEnabled)
                    $sql_params['ORDER BY'] = "cl_to $this->mOrder";
                $res = $this->dbr->select( $this->dbr->tableName('categorylinks'),
                            'DISTINCT cl_to',
                            'cl_from=' . $row['page_id'],
                            'SMW::InlineQuery::Print' , $sql_params);
                return new SMWCategoryIterator($this,$this->dbr,$res,$linked);
            case SMW_IQ_PRINT_RELS:
                if ($smwgIQSortingEnabled)
                    $sql_params['ORDER BY'] = "object_title $this->mOrder";
                $res = $this->dbr->select( $this->dbr->tableName('smw_relations'),
                            'DISTINCT object_title,object_namespace',
                            'subject_id=' . $row['page_id'] . ' AND relation_title=' . $this->dbr->addQuotes($print_data[2]),
                            'SMW::InlineQuery::Print' , $sql_params); 
                return new SMWRelationIterator($this,$this->dbr,$res,$linked);
            case SMW_IQ_PRINT_ATTS:
                if ($smwgIQSortingEnabled) {
                    if ($print_data[3]->isNumeric()) {
                        $sql_params['ORDER BY'] = "value_num $this->mOrder";
                    } else {
                        $sql_params['ORDER BY'] = "value_xsd $this->mOrder";
                    }
                }
                $res = $this->dbr->select( $this->dbr->tableName('smw_attributes'),
                            'DISTINCT value_unit,value_xsd',
                            'subject_id=' . $row['page_id'] . ' AND attribute_title=' . $this->dbr->addQuotes($print_data[2]),
                            'SMW::InlineQuery::Print' , $sql_params);
                return new SMWAttributeIterator($this->dbr, $res, $print_data[3]);
            case SMW_IQ_PRINT_RSEL:
                global $wgContLang;
                return new SMWFixedIterator($this->makeTitleString($wgContLang->getNsText($row[$print_data[2] . 'namespace']) . ':' . $row[$print_data[2] . 'title'],NULL,$linked,$subject));
            case SMW_IQ_PRINT_ASEL: // TODO: allow selection of attribute conditionals, and print them here
                return new SMWFixedIterator('---');
        }
    }


    /*********************************************************************/
    /* Methods for query parsing and execution                           */
    /*********************************************************************/

    /**
     * Callback used for extracting sub queries from a query, and replacing
     * them by some reference for later evaluation.
     */
    function extractSubQuery($matches) {
        global $smwgIQSubQueriesEnabled;
        if ($smwgIQSubQueriesEnabled) {
            $this->mSubQueries[$this->mSQCount] = $matches[1];
            return '+' . $this->mSQCount++;
        } else { return '+'; } // just do a wildcard search instead of processing the subquery
    }

    /**
     * Basic function for extracting a query from a user-supplied string.
     * The result is an object of type SMWSQLQuery.
     */
    private function parseQuery($querytext) {
        global $wgContLang, $smwgIQDisjunctiveQueriesEnabled, $smwgIQSubcategoryInclusions, $smwgIQMaxConditions, $smwgIQMaxTables, $smwgIQMaxPrintout;

        // Extract subqueries:
        $querytext = preg_replace_callback("/<q>(.*)<\/q>/",
                     array(&$this,'extractSubQuery'),$querytext);
        // Extract queryparts: 
        $queryparts = preg_split("/[\s\n]*\[\[|\]\][\s\n]*\[\[|\]\][\s\n]*/",$querytext,-1,PREG_SPLIT_NO_EMPTY);

        $result = new SMWSQLQuery();
        $cat_sep = $wgContLang->getNsText(NS_CATEGORY) . ":";
        $has_namespace_conditions = false; //flag for deciding on including default namespace restrictions

        $pagetable = 't' . $this->mRename++;
        $result->mSelect = array($pagetable . '.page_id', $pagetable . '.page_title' , $pagetable . '.page_namespace'); // always select subject
        $result->mTables = $this->dbr->tableName('page') . " AS $pagetable";

        foreach ($queryparts as $q) {
            $qparts = preg_split("/(::|:=[><]?|^$cat_sep)/", $q, 2, PREG_SPLIT_DELIM_CAPTURE);
            if (count($qparts)<=2) { // $q was not something like "xxx:=yyy", probably a fixed subject
                $qparts = array('','',$q); // this saves a lot of code below ;-)
            }
            $op = $qparts[1];

            if (mb_substr($qparts[2],0,1) == '*') { // conjunct is a print command
                if ( $this->mPrintoutCount < $smwgIQMaxPrintout ) {
                    $altpos = mb_strpos($qparts[2],'|');
                    if (false !==  $altpos) {
                        $label = htmlspecialchars(mb_substr($qparts[2],$altpos+1));
                        $qparts[2] = mb_substr($qparts[2],0,$altpos);
                    } else {
                        $label = ucfirst($qparts[0]);
                        if ( '' === $label) $label = NULL;
                    }
                    if ($cat_sep == $op) { // eventually print all categories for the selected subjects
                        if (NULL === $label) $label = $wgContLang->getNSText(NS_CATEGORY);
                        $result->mPrint['C'] = array($label,SMW_IQ_PRINT_CATS);
                    } elseif ( '::' == $op ) {
                        $result->mPrint['R:' . $qparts[0]] = array($this->makeTitleString($wgContLang->getNsText(SMW_NS_RELATION) . ':' . $qparts[0],$label,true),
                        SMW_IQ_PRINT_RELS,smwfNormalTitleDBKey($qparts[0]));
                    } elseif ( ':=' == $op ) {
                        $av = SMWDataValue::newAttributeValue($qparts[0]);
                        $unit = mb_substr($qparts[2],1);
                        if ($unit != '') { // desired unit selected:
                            $av->setDesiredUnits(array($unit));
                        }
                        $result->mPrint['A:' . $qparts[0]] = array($this->makeTitleString($wgContLang->getNsText(SMW_NS_ATTRIBUTE) . ':' . $qparts[0],$label,true),SMW_IQ_PRINT_ATTS,smwfNormalTitleDBKey($qparts[0]),$av);
                    } // else: operators like :=> are not supported for printing and are silently ignored
                    $this->mPrintoutCount++;
                }
            } elseif ( ($this->mConditionCount < $smwgIQMaxConditions) && ($this->mTableCount < $smwgIQMaxTables) ) { // conjunct is a real condition
                $sq_title = '';
                if (mb_substr($qparts[2],0,1) == '+') { // sub-query or wildcard search
                    $subq_id = mb_substr($qparts[2],1);
                    if ( ('' != $subq_id) && (array_key_exists($subq_id,$this->mSubQueries)) ) {
                        $sq = $this->parseQuery($this->mSubQueries[$subq_id]);
                        if ( ('' != $sq->mConditions) && ($this->mConditionCount < $smwgIQMaxConditions) && ($this->mTableCount < $smwgIQMaxTables) ) {
                            $result->mTables .= ',' . $sq->mTables;
                            if ( '' != $result->mConditions ) $result->mConditions .= ' AND ';
                            $result->mConditions .= '(' . $sq->mConditions . ')';
                            $sq_title = $sq->mSelect[1];
                            $sq_namespace = $sq->mSelect[2];
                        } else {
                            $values = array(); // ignore sub-query and make a wildcard search
                        }
                    }
                    $values = array();
                } elseif ($smwgIQDisjunctiveQueriesEnabled) { // get single values
                    $values = explode('||', $qparts[2]);
                } else {
                    $values = array($qparts[2]);
                }
                $or_conditions = array(); //store disjunctive SQL conditions; simplifies serialisation below
                $condition = ''; // new sql condition
                $curtable = 't' . $this->mRename++; // alias for the current table

                if ($cat_sep == $op ) { // condition on category membership
                    $result->mTables .= ',' . $this->dbr->tableName('categorylinks') . " AS $curtable";
                    $condition = "$pagetable.page_id=$curtable.cl_from";
                    // TODO: make subcat-inclusion more efficient
                    foreach ($values as $idx => $v) {
                        $values[$idx] = smwfNormalTitleDBKey($v);
                    }
                    $this->includeSubcategories($values,$smwgIQSubcategoryInclusions);
                    foreach ($values as $v) {
                        $or_conditions[] = "$curtable.cl_to=" . $this->dbr->addQuotes($v);
                    }
                } elseif ('::' == $op ) { // condition on relations
                    $relation = smwfNormalTitleDBKey($qparts[0]);
                    $result->mTables .= ',' . $this->dbr->tableName('smw_relations') . " AS $curtable";
                    $condition = "$pagetable.page_id=$curtable.subject_id AND $curtable.relation_title=" . $this->dbr->addQuotes($relation);
                    if ('' != $sq_title) { // objects supplied by subquery
                        $condition .= " AND $curtable.object_title=$sq_title AND $curtable.object_namespace=$sq_namespace";
                    } else { // objects given explicitly
                        // TODO: including redirects should become more efficient
                        // (maybe by not creating a full symmetric transitive closure and
                        //  using a simple SQL query instead)
                        //  Also, redirects are not taken into account for sub-queries
                        //  anymore now.
                        $vtitles = array();
                        foreach ($values as $v) {
                            $vtitle = Title::newFromText($v);
                            if (NULL != $vtitle) { 
                                $id = $vtitle->getArticleID(); // create index for title
                                if (0 == $id) $id = $vtitle->getPrefixedText();
                                $vtitles[$vtitle->getArticleID()] = $vtitle; //convert values to titles
                            }
                        }
                        $vtitles = $this->normalizeRedirects($vtitles);
                        
                        // search for values
                        foreach ($vtitles as $vtitle) {
                            //if (NULL != $vtitle) {
                                $or_conditions[] = "$curtable.object_title=" . $this->dbr->addQuotes($vtitle->getDBKey()) . " AND $curtable.object_namespace=" . $vtitle->getNamespace();
                            //}
                        }
                    }
                    if ($relation == $this->mSortkey) {
                        $result->mOrderBy = "$curtable.object_title";
                    }
                } elseif ('' == $op) { // fixed subject, possibly namespace restriction
                    if ('' != $sq_title) { // objects supplied by subquery
                        $condition = "$pagetable.page_title=$sq_title AND $pagetable.page_namespace=$sq_namespace";
                    } else { // objects given explicitly
                        //Note: I do not think we have to include redirects here. Redirects should not 
                        //      have annotations, so one can just write up the query correctly! -- mak    
                        foreach ($values as $v) {
                            $v = smwfNormalTitleDBKey($v);
                            if ((mb_strlen($v)>2) && (':' == mb_substr($v,0,1))) $v = mb_substr($v,1); // remove initial ':'
                            // TODO: should this be done when normalizing the title???
                            $ns_idx = $wgContLang->getNsIndex(mb_substr($v,0,-2)); // assume format "Namespace:+"
                            if ((false === $ns_idx)||(mb_substr($v,-2,2) !== ':+')) {
                                $vtitle = Title::newFromText($v);
                                if (NULL != $vtitle) {
                                    $or_conditions[] = "$pagetable.page_title=" . $this->dbr->addQuotes($vtitle->getDBKey()) . " AND $pagetable.page_namespace=" . $vtitle->getNamespace();
                                    $result->mFixedSubject = true; // by default, only this case is a really "fixed" subject (even though it could still be combined with others); TODO: find a better way for deciding whether to show the first column or not
                                    $has_namespace_conditions = true; // fixed subjects might have namespaces, so we must discard any overall namespace restrictions to retrieve results
                                }
                            } else {
                                $or_conditions[] = "$pagetable.page_namespace=$ns_idx";
                                $has_namespace_conditions = true;
                            }
                        }
                    }
                } else { // some attribute operator
                    $attribute = smwfNormalTitleDBKey($qparts[0]);
                    $av = SMWDataValue::newAttributeValue($attribute);
                    switch ($op) {
                        case ':=>': $comparator = '>='; break;
                        case ':=<': $comparator = '<='; break;
                        default: $comparator = '=';
                    }
                    foreach ($values as $v) {
                        $av->setUserValue($v);
                        if ($av->isValid()) {// TODO: it should be possible to ignore the unit for many types
                            if ($av->isNumeric()) {
                                $or_conditions[] = "$curtable.value_num$comparator" . $av->getNumericValue() . " AND $curtable.value_unit=" . $this->dbr->addQuotes($av->getUnit()); 
                            } else {
                                $or_conditions[] = "$curtable.value_xsd$comparator" . $this->dbr->addQuotes($av->getXSDValue()) . " AND $curtable.value_unit=" . $this->dbr->addQuotes($av->getUnit()); 
                            }
                        }
                    }
                    $result->mTables .= ',' . $this->dbr->tableName('smw_attributes') . " AS $curtable";
                    $condition = "$pagetable.page_id=$curtable.subject_id AND $curtable.attribute_title=" . $this->dbr->addQuotes($attribute);
                    if ($attribute == $this->mSortkey) {
                        if ($av->isNumeric()) $result->mOrderBy = $curtable . '.value_num';
                          else $result->mOrderBy = $curtable . '.value_xsd';
                    }
                }

                // build query from disjuncts:
                $firstcond = true;
                foreach ($or_conditions as $cond) {
                    if ($this->mConditionCount >= $smwgIQMaxConditions) break;
                    if ($firstcond) {
                        if ('' != $condition) $condition .= ' AND ';
                        $condition .= '((';
                        $firstcond = false;
                    } else {
                        $condition .= ') OR (';
                        $this->mConditionCount++; // (the first condition is counted with the main part)
                    }
                    $condition .= $cond;
                }
                if (count($or_conditions)>0) $condition .= '))';
                if ('' != $condition) {
                    if ('' != $result->mConditions) $result->mConditions .= ' AND ';
                    $this->mConditionCount++;
                    $this->mTableCount++;
                    $result->mConditions .= $condition;
                }
            }
        }

        if (!$has_namespace_conditions) { // restrict namespaces to default setting
            global $smwgIQSearchNamespaces;
            if ($smwgIQSearchNamespaces !== NULL) {
                $condition = '';
                foreach ($smwgIQSearchNamespaces as $nsid) {
                    if ($condition == '') {
                        $condition .= '((';
                    } else {
                        $condition .= ') OR (';
                    }
                    $condition .= "$pagetable.page_namespace=$nsid";
                    $this->mConditionCount++; // we do not check whether this exceeds the max, since it is somehow crucial and controlled by the site admins anyway
                }
                if ($condition != '') $condition .= '))';
                if ('' != $result->mConditions) $result->mConditions .= ' AND ';
                $result->mConditions .= $condition;
            }
        }

        $result->mDebug = "\n SELECT " . implode(',',$result->mSelect) . "\n FROM $result->mTables\n WHERE $result->mConditions" . " \n Conds:$this->mConditionCount Tables:$this->mTableCount Printout:$this->mPrintoutCount"; //DEBUG

        return $result;
    }

    /**
     * Turns an array of article titles into an array of all these articles and
     * the transitive closure of all redirects from and to this articles.
     * Or, simply said: it gets all aliases of what you put in.
     *
     * FIXME: store intermediate result in a temporary DB table on the heap; much faster!
     * FIXME: include an option to ignore multiple redirects, or, even better, make a 
     * plugable SQL-query to compute one-step back-and-forth redirects without any 
     * materialisation.
     */
    private function normalizeRedirects(&$titles) {
        global $smwgIQRedirectNormalization;
        if (!$smwgIQRedirectNormalization) {
            return $titles;
        }

        $stable = 0;
        $check_titles = array_diff( $titles , array() ); // Copies the array
        while ($stable<30) { // emergency stop after 30 iterations
            $stable++;
            $new_titles = array();
            foreach ( $check_titles as $title ) {
                // there...
                if ( 0 != $title->getArticleID() ) {
                    $res = $this->dbr->select(
                        array( 'page' , 'pagelinks' ),
                        array( 'pl_title', 'pl_namespace'),
                        array( 'page_id = ' . $title->getArticleID(),
                            'page_is_redirect = 1',
                            'page_id = pl_from' ) ,
                            'SMW::InlineQuery::NormalizeRedirects', array('LIMIT' => '1') );
                    while ( $res && $row = $this->dbr->fetchRow( $res )) {
                        $new_title = Title::newFromText($row['pl_title'], $row['pl_namespace']);
                        if (NULL != $new_title) {
                            $id = $new_title->getArticleID();
                            if (0 == $id) $id = $new_title->getPrefixedText();
                            if (!array_key_exists( $id , $titles)) {
                                $titles[$id] = $new_title;
                                $new_titles[] = $new_title;
                            }
                        }
                    }
                    $this->dbr->freeResult( $res );
                }

                // ... and back again
                $res = $this->dbr->select(
                    array( 'page' , 'pagelinks' ),
                    array( 'page_id' ),
                    array( 'pl_title = ' . $this->dbr->addQuotes( $title->getDBkey() ),
                           'pl_namespace = ' . $this->dbr->addQuotes( $title->getNamespace() ), 
                           'page_is_redirect = 1',
                           'page_id = pl_from' ) ,
                           'SMW::InlineQuery::NormalizeRedirects', array('LIMIT' => '1'));
                while ( $res && $row = $this->dbr->fetchRow( $res )) {
                    $new_title = Title::newFromID( $row['page_id'] );
                    if (!array_key_exists( $row['page_id'] , $titles)) {
                        $titles[$row['page_id']] = $new_title;
                        $new_titles[] = $new_title;
                    }
                }
                $this->dbr->freeResult( $res );
            }
            if (count($new_titles)==0)
                $stable= 500; // stop
            else
                $check_titles = array_diff( $new_titles , array() );
        }
        return $titles;
    }
    
    /**
     * Turns an array of categories to an array of categories and its subcategories.
     * The number of relations followed is given by $levels.
     *
     * FIXME: store intermediate result in a temporary DB table on the heap; much faster!
     */
    private function includeSubcategories( &$categories, $levels ) {
        if (0 == $levels) return $categories;

        $checkcategories = array_diff($categories, array()); // Copies the array
        for ($level=$levels; $level>0; $level--) {
            $newcategories = array();
            foreach ($checkcategories as $category) {
                $res = $this->dbr->select( // make the query
                    array( 'categorylinks', 'page' ),
                    array( 'page_title' ),
                    array(  'cl_from = page_id ',
                            'page_namespace = '. NS_CATEGORY,
                            'cl_to = '. $this->dbr->addQuotes($category) ) ,
                            "SMW::SubCategory" );
                if ( $res ) {
                    while ( $res && $row = $this->dbr->fetchRow( $res )) {
                        if ( array_key_exists( 'page_title' , $row) ) {
                            $new_category = $row[ 'page_title' ];
                            if (!in_array($new_category, $categories)) {
                                $newcategories[] = smwfNormalTitleDBKey($new_category);
                            }
                        }
                    }
                    $this->dbr->freeResult( $res );
                }
            }
            if (count($newcategories) == 0) {
                return $categories;
            } else {
                $categories = array_merge($categories, $newcategories);
            }
            $checkcategories = array_diff($newcategories, array());
        }
        return $categories;
    }

    /**
     * Create output string for an article title (possibly including namespace)
     * as given by $text. 
     *
     * $subject states whether the given title is the subject (to which special
     * settings for linking apply).
     * If $label is null the standard label of the given article will be used.
     * If $label is the empty string, an empty string is returned.
     * $linked states whether the result should be a hyperlink
     * $exists states whether $text is known to be anexisting article, in which 
     *     case we can save a DB lookup when creating links.
     */
    public function makeTitleString($text,$label,$linked,$exists=false) {
        if ( '' === $label) return ''; // no link desired
        $title = Title::newFromText( $text );
        if ($title === NULL) {
            return $text; // TODO maybe report an error here?
        } elseif ( $linked ) {
            if (in_array($title->getNameSpace(), array(NS_IMAGE, NS_CATEGORY))) $prefix = ':';
            return '[['.$prefix.$title->getPrefixedText().'|'.$title->getText().']]';
        } else {
            return $title->getText(); // TODO: shouldn't this default to $label?
        }
    }

    /**
     * Main entry point for parsing, executing, and printing a given query text.
     */
    public function getWikiResult( $text ) {
        global $smwgIQSortingEnabled, $smwgIQRunningNumber,$wgTitle;
        $this->mQueryText = $text;

        if (!isset($smwgIQRunningNumber)) {
            $smwgIQRunningNumber = 0;
        } else { $smwgIQRunningNumber++; }

        // This should be the proper way of substituting templates in a safe and comprehensive way    
        $parser = new Parser();
        $parserOptions = new ParserOptions();
        //$parserOptions->setInterfaceMessage( true );
        $parser->startExternalParse( $wgTitle, $parserOptions, OT_MSG );
        $text = $parser->transformMsg( $text, $parserOptions );

        $this->dbr =& wfGetDB( DB_SLAVE ); // Note: if this fails, there were worse errors before; don't check it
        $this->mRename = 0;
        $this->mSubQueries = array();
        $this->mSQCount = 0;
        $this->mConditionCount = 0;
        $this->mTableCount = 0;
        $this->mPrintoutCount = 0;
        $sq = $this->parseQuery($text);

        $sq->mSelect[0] .= ' AS page_id';
        $sq->mSelect[1] .= ' AS page_title';
        $sq->mSelect[2] .= ' AS page_namespace';

        $sql_options = array('LIMIT' => $this->mLimit + 1, 'OFFSET' => $this->mOffset); // additional options (order by, limit)
        if ( $smwgIQSortingEnabled ) {
            if ( NULL == $sq->mOrderBy ) {
                $sql_options['ORDER BY'] = "page_title $this->mOrder "; // default
            } else {
                $sql_options['ORDER BY'] = "$sq->mOrderBy $this->mOrder ";
            }
        }

        if ($this->mShowDebug) {
            return $sq->mDebug; // DEBUG
        }

        //*** Execute the query ***//
        $this->mQueryResult = $this->dbr->select( 
                 $sq->mTables,
                 'DISTINCT ' . implode(',', $sq->mSelect),
                 $sq->mConditions,
                 "SMW::InlineQuery" ,
                 $sql_options );

        //*** Create the output ***//

        //No results, TODO: is there a better way than calling numRows (which counts all results)?
        if ( (!$this->mQueryResult) || (0 == $this->dbr->numRows( $this->mQueryResult )) ) return $this->mDefault;

        // Cases in which to print the subject:
        if ((!$sq->mFixedSubject) || (0 == count($sq->mPrint)) || (NULL != $this->mMainLabel)) { 
            if (NULL == $this->mMainLabel) {
                $sq->mPrint = array('' => array('',SMW_IQ_PRINT_RSEL,'page_')) + $sq->mPrint;
            } else {
                $sq->mPrint = array('' => array($this->mMainLabel,SMW_IQ_PRINT_RSEL,'page_')) + $sq->mPrint;
            }
        }

        //Determine format if 'auto', also for backwards compatibility
        if ( 'auto' == $this->mFormat ) {
            if ( (count($sq->mPrint)>1) && ($this->mLimit > 0) )
                $this->mFormat = 'table';
            else $this->mFormat = 'list';
        }

        switch ($this->mFormat) {
            case 'table': case 'broadtable':
                $printer = new SMWWikiTablePrinter($this,$sq);
                break;
            case 'ul': case 'ol': case 'list': default: 
                $printer = new SMWWikiListPrinter($this,$sq);
        }
        $result = $printer->printResult();
        $this->dbr->freeResult($this->mQueryResult); // Things that should be free: #42 "Possibly large query results"

        return $result;
    }

}


/**
 * Wiki printer for tabular data.
 */
class SMWWikiTablePrinter implements SMWQueryPrinter {
    private $mIQ; // the querying object that called the printer
    private $mQuery; // the query that was executed and whose results are to be printed

    public function SMWWikiTablePrinter($iq, $query) {
        $this->mIQ = $iq;
        $this->mQuery = $query;
    }
    
    public function printResult() {
        global $smwgIQRunningNumber;

        // print header
        if ('broadtable' == $this->mIQ->getFormat())
            $widthpara = ' width="100%"';
        else $widthpara = '';
        $result = $this->mIQ->getIntro() . "{| class=\"smwtable\"$widthpara id=\"querytable" . $smwgIQRunningNumber . "\"\n";
        if ($this->mIQ->showHeaders()) {
            #$result .= "|-";
            foreach ($this->mQuery->mPrint as $print_data) {
                $result .= "! " . $print_data[0] . "\n";
            }
        }

        // print all result rows
        while ( $row = $this->mIQ->getNextRow() ) {
            $result .= "|- \n";
            $firstcol = true;
            foreach ($this->mQuery->mPrint as $print_data) {
                $iterator = $this->mIQ->getIterator($print_data,$row,$firstcol);
                $result .= "| ";
                $first = true;
                while ($cur = $iterator->getNext()) {
                    if ($first) $first = false; else $result .= '<br />';
                    $result .= $cur[0];
                }
                $firstcol = false;
                $result .= "\n";
            }
        }

        if ($this->mIQ->isInline() && $this->mIQ->hasFurtherResults()) {
            $label = $this->mIQ->getSearchLabel();
            if ($label === NULL) { //apply default
                $label = wfMsgForContent('smw_iq_moreresults');
            }
            if ($label != '') {
                $result .= "|- \n| class=\"sortbottom\" colspan=\"" . count($this->mQuery->mPrint) . '\" | [' . $this->mIQ->getQueryURL() . ' ' . $label . ']'."\n\n";
            }
        }

        // print footer
        $result .= "|}\n";

        return $result;
    }
}

/**
 * Printer for wiki list data. Somewhat confusing code, since one has to iterate through lists,
 * inserting texts in between their elements depending on whether the element is the first
 * that is printed, the first that is printed in parentheses, or the last that will be printed.
 * Maybe one could further simplify this.
 */
class SMWWikiListPrinter implements SMWQueryPrinter {
    private $mIQ; // the querying object that called the printer
    private $mQuery; // the query that was executed and whose results are to be printed

    public function SMWWikiListPrinter($iq, $query) {
        $this->mIQ = $iq;
        $this->mQuery = $query;
    }
    
    public function printResult() {
        // print header
        $result = $this->mIQ->getIntro();
        if ( ('ul' == $this->mIQ->getFormat()) || ('ol' == $this->mIQ->getFormat()) ) {
            $result .= '';//'<' . $this->mIQ->getFormat() . '>';
            $footer = '';//'</' . $this->mIQ->getFormat() . '>';
            $rowstart = ('ul' == $this->mIQ->getFormat()?'* ':'# ');
            $rowend = "\n";
            $plainlist = false;
        } else {
            $params = $this->mIQ->getParameters();
            if (array_key_exists('sep', $params)) {
                $listsep = htmlspecialchars(str_replace('_', ' ', $params['sep']));
                $finallistsep = $listsep;
            } else {  // default list ", , , and, "
                $listsep = ', ';
                $finallistsep = wfMsgForContent('smw_finallistconjunct') . ' ';
            }
            $footer = '';
            $rowstart = '';
            $rowend = '';
            $plainlist = true;
        }

        // print all result rows
        $first_row = true;
        $row = $this->mIQ->getNextRow();
        while ( $row ) {
            $nextrow = $this->mIQ->getNextRow(); // look ahead
            if ( !$first_row && $plainlist )  {
                if ($nextrow) $result .= $listsep; // the comma between "rows" other than the last one
                else $result .= $finallistsep;
            } else $result .= $rowstart;

            $first_col = true;
            $found_values = false; // has anything but the first coolumn been printed?
            foreach ($this->mQuery->mPrint as $print_data) {
                $iterator = $this->mIQ->getIterator($print_data,$row,$first_col);
                $first_value = true;
                while ($cur = $iterator->getNext()) {
                    if (!$first_col && !$found_values) { // first values after first column
                        $result .= ' (';
                        $found_values = true;
                    } elseif ($found_values || !$first_value) { 
                      // any value after '(' or non-first values on first column
                        $result .= ', ';
                    }
                    if ($first_value) { // first value in any column, print header
                        $first_value = false;
                        if ( $this->mIQ->showHeaders() && ('' != $print_data[0]) ) {
                            $result .= $print_data[0] . ' ';
                        }
                    }
                    $result .= $cur[0]; // actual output value
                }
                $first_col = false;
            }
            if ($found_values) $result .= ')';
            $result .= $rowend;
            $first_row = false;
            $row = $nextrow;
        }
        
        if ($this->mIQ->isInline() && $this->mIQ->hasFurtherResults()) {
            $label = $this->mIQ->getSearchLabel();
            if ($label === NULL) { //apply defaults
                if ('ol' == $this->mIQ->getFormat()) $label = '';
                else $label = wfMsgForContent('smw_iq_moreresults');
            }
            if (!$first_row && !in_array($this->mIQ->getFormat(), array('ol','ul'))) $result .= ' '; // relevant for list
            if ($label != '') {
                global $wgServer;
                $result .= $rowstart . '[' . $wgServer . $this->mIQ->getQueryURL() . ' ' . $label . ']' . $rowend;
            }
        }

        // print footer
        $result .= $footer;

        return $result;
    }


}

} # End MW Environment wrapper
//</source>
?>