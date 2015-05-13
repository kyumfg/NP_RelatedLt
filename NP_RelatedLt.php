<?php
/* NP_RelatedLt v0.1
 * 
 * NP_MetaEXで登録したkeywordsからサイト内の関連する記事一覧を出力
 * NP_MetaEXがインストールされていなかったり、keywordsが登録されていない場合は、記事のタイトルをキーにする
 * NP_Related Ver0.4 (http://nucleus.datoka.jp/) 改造
 * 
 * v0.1  2015.05.10 初版 kyu
*/

class NP_RelatedLt extends NucleusPlugin {
    function getName()       {return 'RelatedLt';}
    function getAuthor()     {return 'kyu';}
    function getURL()        {return 'mailto:kyumfg@gmail.com';}
    function getVersion()    {return '0.1[2015.05.10]';}
    function supportsFeature($w) {return in_array($w, array('SqlTablePrefix','SqlApi'));}
    function getDescription() {return 'NP_MetaEXで登録したkeywordsからサイト内の関連記事リンクをリスト化して表示します。NP_MetaEXがインストールされていなかったりkeywordsが登録されていない場合は、記事のタイトルをキーにします。スキンへの記述例：<%RelatedLt(表示件数)%>';}

    function init() {
        global $manager, $blog, $CONF;

        if ($blog) $b =& $blog; 
        else $b =& $manager->getBlog($CONF['DefaultBlog']);
        $bid = $b->getID();

        $this->header_lc = $this->getOption("header_lc");
        $this->header_end = $this->getOption("header_end");

        $this->list_header = $this->getOption("listheading");
        $this->list_footer = $this->getOption("listfooter");
        $this->item_header = $this->getOption("itemheading");
        $this->item_footer = $this->getOption("itemfooter");

        $this->notitle = $this->getOption("notitle");
        $this->noresults = $this->getOption("noresults");
        $this->flg_noheader = $this->getOption("flg_noheader");
        $this->morelink = $this->getOption("morelink");
        $this->maxlength = $this->getOption("maxlength");
        $this->maxlength2 = $this->getOption("maxlength2");
        $this->flg_snippet = $this->getOption('flg_snippet');
        $this->flg_timelocal = $this->getOption('flg_timelocal');
        $this->currentblog = $this->getOption("currentblog");
        $this->searchrange = $this->getOption("searchrange");
        $this->flg_srchcond_and = $this->getOption("flg_srchcond_and");
    }

    function install() {
        $this->createOption("header_lc", "見出しの開始", "text", "<h2>関連するオススメ記事");
        $this->createOption("header_end",  "見出しの終了", "text", "</h2>");
        $this->createOption("listheading", "リストの開始", "text", "<ul class='related'>\n");
        $this->createOption("listfooter",  "リストの終了", "text", "</ul>\n");      
        $this->createOption("itemheading", "リストアイテムの開始", "text", "<li>\n");
        $this->createOption("itemfooter",  "リストアイテムの終了", "text", "</li>\n");
        $this->createOption("notitle",   "題名がないとき", "text", "(no title)");
        $this->createOption("noresults", "検索結果がないとき", "text", "<p>関連する記事はありません</p>");
        $this->createOption("flg_noheader", "検索結果がないときは見出しを表示しない", "yesno", "yes");
        $this->createOption("morelink",  "MOREリンク", "text", "もっと見る...");
        $this->createOption("maxlength", "題名の長さ上限", "text", "60");
        $this->createOption("flg_snippet", "本文を表示", "yesno", "no");
        $this->createOption("maxlength2", "本文の長さ上限", "text", "120");
        $this->createOption("flg_timelocal", "タイムスタンプ表示", "yesno", "yes");
        $this->createOption("currentblog", "同一ブログ内のみ検索", "yesno", "yes");
        $this->createOption('searchrange', "検索対象", 'select', 'type3', 
            'Title|type1|Title, Body|type2|Title, Body, More|type3');
        $this->createOption("flg_srchcond_and", "AND検索", "yesno", "no");
        $this->createOption("flg_erase", "アンインストール時に全てのデータを削除", "yesno", "no");
    }

    function doSkinVar($skinType, $max='5', $showsnippet='', $skinquery='', $searchcond='') {
        global $manager, $itemid;

        if ($skinType == 'item') {
            $item =& $manager->getItem($itemid,0,0);
        }
        else if ($skinquery != '') {
            $item = array(
                    'itemid' => 0, //dummy
                    'title' => $skinquery,
                );
        }
        else if ($skinType == 'search') {
            $item = array(
                    'itemid' => 0, //dummy
                    'title' => requestVar('query'),
                );
        }
        else {
            return;
        }

        $this->doTemplateVar($item, $max, $showsnippet, $searchcond, $skinType);
    }

    function doTemplateVar(&$item, $max='5', $showsnippet='', $searchcond='', $skinType='item') {
        global $manager, $blog, $CONF;

        if ($showsnippet == '') $showsnippet = $this->flg_snippet;
        if ($showsnippet == 'true' or $showsnippet == 'yes') $showsnippet = true;
        else if ($showsnippet == 'false' or $showsnippet == 'no') $showsnippet = false;
        $this->showsnippet = $showsnippet;

        if($blog){
            $b =& $blog; 
        }else{
            $b =& $manager->getBlog($CONF['DefaultBlog']);
        }
        if (is_object($item)) $item = get_object_vars($item);
        $max = intval($max);
        $del_style   = array("/(ver|version)[0-9.]+[0-9a-z.]*$/i", "/\.$/", "/-[0-9]+-$/");
        $quote_style = "/『(.+)』|「(.+)」|\"(.+)\"|”(.+)”|\'(.+)\'|’(.+)’|\((.+)\)|（(.+)）|【(.+)】|\[(.+)\]/";

        $q = '';
        $id = $item['itemid'];

		if ($manager->pluginInstalled('NP_MetaEX')) {
            $result = sql_query("SELECT keywords FROM ". sql_table("plug_metaex") ." WHERE itemid='$id'");
            if ($msg = sql_fetch_array($result)) {
                if ($msg['keywords'] == "DONOTSEARCH") $donotsearch = true;
                else $q = $msg['keywords'];
            }
        }

         // Is there a keyword present?
        if ($q == "") { 
            $q = strip_tags($item['title']);
        }
         if ($donotsearch) {
            if ($this->flg_noheader == 'yes') return;
            $this->_show_header($q);
            echo $this->noresults;
            return;
        }
        else if ($q == ""){
            if ($this->flg_noheader == 'yes') return;
            $this->_show_header('(No words)');
            echo $this->noresults;
            return;
        }

        // prepare for multi-word search
        $q = trim($q);
        $dispq = $q;
        $str_where = '';
        $ary_modq = array();

        // quoted words
        $qt_num = 0;
        $ary_quote = array();
        while ( preg_match($quote_style, $q, $quoted_keys) ) {
            $qlastidx = count($quoted_keys) -1;

//E         if (preg_match("/^[0-9]+$/", $quoted_keys[$qlastidx]) ) { 
            if (preg_match("/^[0-9]+$/", mb_convert_kana($quoted_keys[$qlastidx], 'n', _CHARSET)) ) { 
                // delete series num
                $q = preg_replace("/". preg_quote($quoted_keys[0]) ."/", '', $q);
                continue;
            }
            $qrep = "__QUOTED{$qt_num}__";

            // add comma around a quote for splitting
            $ary_quote[$qt_num][0] = stripslashes($quoted_keys[0]); // use first match(with quote chars)
            $ary_quote[$qt_num][1] = stripslashes($quoted_keys[$qlastidx]); //use last(without quote chars)
            $q = preg_replace("/". preg_quote($quoted_keys[0]) ."/", ",$qrep,", $q);
            $qt_num ++;
        }

        // split and make multi keywords
        $q = mb_convert_kana($q, 's', _CHARSET);
        $ary_q = preg_split("/\s+|,|、|。|:|：/", $q, -1, PREG_SPLIT_NO_EMPTY);

        // set search condition type
        if (strtoupper($searchcond) == 'AND' ||
            strtoupper($searchcond) == 'OR') $qcat = $searchcond;
        else if ($this->flg_srchcond_and == 'yes') $qcat = 'AND';
        else $qcat = 'OR';

        foreach ($ary_q as $qpiece) {
            if (preg_match("/^__QUOTED([0-9]+)__$/", $qpiece, $qmatch)) {
                $ary_modq[] = $ary_quote[$qmatch[1]][0]; // with quote chars
                $qpiece = $ary_quote[$qmatch[1]][1];  // without quote chars
            }
            else {
                $qpiece = preg_replace($del_style, '', $qpiece);
                if (mb_strlen($qpiece,_CHARSET) < 2) continue; // skip if the key is one letter
                $ary_modq[] = $qpiece;
            }

            $qpiece = mysql_escape_string($qpiece);

            $str_cat = ($str_where) ? " $qcat " : '';

            switch ($this->searchrange) {
                case 'type1':
                    $str_where .= $str_cat ."( ititle LIKE '%$qpiece%' )";
                break;
                case 'type2':
                    $str_where .= $str_cat ."( ititle LIKE '%$qpiece%' OR ibody LIKE '%$qpiece%' )";
                break;
                case 'type3':
                    $str_where .= $str_cat ."( ititle LIKE '%$qpiece%' OR ibody LIKE '%$qpiece%' OR imore LIKE '%$qpiece%' )";
                break;
            }

//          if (count($ary_modq) == 3) break; // max 3 words
            if (count($ary_modq) == 6) break; // max 6 words
        }
        $qmore = join($ary_modq, ' '); // for 'and more' query link

        // Select only from same weblog?
        if ($this->currentblog == 'yes' and $skinType == 'item') {
            $result = sql_query("SELECT iblog FROM ". sql_table("item") ." WHERE inumber='$item[itemid]'");
            $msg = sql_fetch_array($result);
            $bid = $msg['iblog'];
            $str_iblog = " AND iblog='$bid'";
        } else {
            $str_iblog = '';
        }
        $result = sql_query("SELECT inumber, ititle, itime, ibody FROM ". sql_table("item") 
            ." WHERE ($str_where)" . $str_iblog
                    ." AND idraft=0 AND inumber<>'$id'" 
//          ." AND itime<=" . mysqldate($b->getCorrectTime())
//          ." ORDER BY inumber DESC LIMIT 0,$max");
            ." ORDER BY RAND() LIMIT 0,$max");
        // Do we have any rows?
        if (@mysql_num_rows($result) > 0) {
            $this->_show_header($qmore);

            $first=true;
            while ($row = sql_fetch_object($result)) {

                if ($first){
                    $first=false; 
                    echo $this->list_header;
                }

                // prepare
                if (empty($row->ititle)) $title = $this->notitle;
                else $title = shorten(strip_tags($row->ititle),$this->maxlength,'...');
//              $itime = "[$row->itime]";
                $itime = date('Y/m/d', strtotime($row->itime));
                $snippet = shorten(strip_tags($row->ibody),$this->maxlength2,'...');

                $iid = $row->inumber;
                $bid = getBlogIDFromItemID($iid);
                $b_tmp =& $manager->getBlog($bid);
                $blogurl = $b_tmp->getURL() ;
                if(!$blogurl){ 
                    $blogurl = $this->defaultblogurl; 
                } 
                if ($CONF['URLMode'] == 'pathinfo'){ 
                    if(substr($blogurl, -1) != '/') 
                    $blogurl .= '/';
                    $url = $blogurl .'item/'. $iid;
                }
                else {
                    $url = createItemLink($iid);
                }
                
                $this->_show_list($url, $title, $snippet, $itime);
            }

            $this->_show_morelink($qmore, $b->getID());

            if (!$first) echo $this->list_footer;
        } 
        else {
            if ($this->flg_noheader == 'yes') return;
            $this->_show_header($qmore);
            echo $this->noresults;
        }
    }

    // Custom functions

    function _make_stamp() {
        return strtotime ("now");
    }

    function _show_header($q) {
//              echo $this->header_lc .$q. $this->header_end;
                echo $this->header_lc . $this->header_end;
    }

    function _show_list($url, $title, $snippet='', $time='') {
        echo "\n" . $this->item_header;

        if ($this->showsnippet) {
            if ($this->flg_timelocal == 'yes') 
//              echo '<a href="'. $url .'">'. $title .' '. $time .'</a>';
                echo '<a href="'. $url .'">'. $title .'<span class="date">'. $time .'</span></a>';
            else 
                echo '<a href="'. $url .'" title="'. $time .'">'. $title .'</a>';
                echo '<br /> <span class="iteminfo">'. $snippet .'</span>';
        }
        else {
            if ($this->flg_timelocal == 'yes') 
//              echo '<a href="'. $url .'" title="'. $snippet .'">'. $title .' '. $time .'</a>';
                echo '<a href="'. $url .'" title="'. $snippet .'">'. $title .'</a><span class="date"> ('. $time .')</span></a>';
            else 
//              echo '<a href="'. $url .'" title="'. $snippet . $time .'">'. $title . '</a>';
                echo '<a href="'. $url .'" title="'. $snippet .'">'. $title . '</a>';
        }

        echo $this->item_footer;
    }

    function _show_morelink($q, $extra='') {
        global $CONF;

        if ($this->morelink == '') return;

        echo "\n". $this->item_header;
        $bid = $extra;
        if ($CONF['URLMode'] == 'pathinfo'){
            $moreurl = $CONF['BlogURL'].'?amount=0&amp;query='. urlencode($q) .'&blogid='.$bid;
        }
        else {
            $moreurl = createBlogidLink($bid) . '&amp;amount=0&amp;query='. urlencode($q);
        }
        echo '<a href="' . $moreurl . '" title="'. "サイト内検索へジャンプします" .'">'
            . $this->morelink.'</a>';
        echo $this->item_footer;
    }
}
?>