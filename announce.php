<?php
    chdir(dirname(__FILE__));
    include('config.php');

    $send_mail = 0;
    $cmd_sum = 0;

    $dbh = mysql_connect($dbHost,$dbUser,$dbPassword);
    $res = mysql_select_db($dbPrefix);
    mysql_query('SET NAMES utf8');

    $download = "http://rating.chgk.info/teams.php?displayteam=$id_cmd&formula=a";
    $file_gray = getPage($download);
    file_put_contents('./page_gray.txt', $file_gray);

    $download = "http://rating.chgk.info/teams.php?displayteam=$id_cmd&formula=b";
    $file_yellow = getPage($download);
    file_put_contents('./page_yellow.txt', $file_yellow);

    $html = prepareForDOM($file_gray, 'windows-1251');
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath_gray = new DOMXPath($dom);

    $html = prepareForDOM($file_yellow, 'windows-1251');
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath_yellow = new DOMXPath($dom);

    $text = $txt['greeting'] . "\n\n";

    // Находим текущее и прогнозируемое места в рейтинге
    $ratingFuture_gray = trim($xpath_gray->query("//table[@id='ratings']/tr/td[3]")->item(0)->nodeValue);
    $ratingNow_gray = trim($xpath_gray->query("//table[@id='ratings']/tr/td[3]")->item(1)->nodeValue);

    $ratingFuture_yellow = trim($xpath_yellow->query("//table[@id='ratings']/tr/td[4]")->item(0)->nodeValue);
    $ratingNow_yellow = trim($xpath_yellow->query("//table[@id='ratings']/tr/td[4]")->item(1)->nodeValue);

    // Разбираем таблицу турниров

    // Определяем рейтинг турнира, минимально необходимый для того чтобы турнир влиял на рейтинг команды
    $rateMin = 9999;
    $ratedElements = $xpath_gray->query("//td[@class='rated']");
    if (!is_null($ratedElements)) {
        foreach ($ratedElements as $element) {
            if ($rateMin > trim($element->nodeValue)) { $rateMin = trim($element->nodeValue);}
        }
    }

    // Ищем турнир, отсутствующий в базе

    $tournaments = $xpath_gray->query("//table[contains(@class,'tournaments_table')]/tbody/tr[not(contains(., 'Сезон'))]");

    foreach ($tournaments as $tournament) {

        $tour_array = domNodeToArray($tournament);
        $tourNum = $tour_array["td"][1];
        $tourTitle = @$tour_array["td"][2]["span"][0]["a"];
        if (!$tourTitle) { $tourTitle = $tour_array["td"][2]["span"]["a"]; }
        $tourDate = $tour_array["td"][4]["span"];
        $tourPlace = $tour_array["td"][7];
        $tourRate = $tour_array["td"][9]["span"];

        // Берем только турниры, подходящие по дате
        if ((time()-(60*60*24*30*$stat_period)) > strtotime($tourDate)) { break; }

        $placePredicted_yellow = trim($xpath_yellow->query("//table[contains(@class,'tournaments_table')]/tbody/tr[contains(., $tourNum)]/td[8]")->item(0)->nodeValue);
        $placeGained_yellow = trim($xpath_yellow->query("//table[contains(@class,'tournaments_table')]/tbody/tr[contains(., $tourNum)]/td[9]")->item(0)->nodeValue);
        $ratingDelta_yellow = trim($xpath_yellow->query("//table[contains(@class,'tournaments_table')]/tbody/tr[contains(., $tourNum)]/td[12]")->item(0)->nodeValue);

        $is_inbase = 0;
        $query = mysql_query("SELECT * FROM $dbName") OR die ('Error: ' . mysql_error());
        while ($row = mysql_fetch_assoc($query)) {
            if ($row['id_base'] == $tourNum) { $is_inbase = 1; }
            }

        if (!$is_inbase && $tourRate>0) {
            $send_mail = 1;
            $text = $text . "$tourTitle ($tourDate), $tourPlace место. \n";
            if ($tourRate > $rateMin) {
                $text = $text . "Серый рейтинг: $tourRate. Турнир СКОРЕЕ ВСЕГО ПОШЕЛ в зачет.\n";
            } else {
              $text = $text . "Серый рейтинг: $tourRate. Турнир НЕ ПОШЕЛ в зачет.\n";
            }

            $text = $text . "Желтый рейтинг: предсказанное место $placePredicted_yellow, занятое место $placeGained_yellow, изменение в рейтинге $ratingDelta_yellow";
            if (strpos($ratingDelta_yellow, '[') !== FALSE) {
                $text = $text . " (не идет в зачет).\n ";
            } else {
               $text = $text . ".\n ";
            }

            $tourNum = mysql_real_escape_string($tourNum);
            $tourTitle = mysql_real_escape_string($tourTitle);
            $tourDate = mysql_real_escape_string($tourDate);
            $tourPlace = mysql_real_escape_string($tourPlace);
            $tourRate = mysql_real_escape_string($tourRate);

            $query = mysql_query("INSERT INTO $dbName (id_base, name, date, place, rating, is_top)
            VALUES ($tourNum, '$tourTitle', '$tourDate', '$tourPlace', $tourRate, 0)") OR die ('Error: ' . mysql_error());

            // Считаем плюсики
            setlocale(LC_ALL, 'ru_RU.UTF-8');
            $text = $text . "\n===== Немного статистики (только по ТОП 10 турнира) =====\n\n";

            // Скачиваем таблицу с плюсиками
            $url = "http://rating.chgk.info/tournaments.php?tournament_id=". $tourNum . "&download_data=export_tournament_tour";
            $content = getPage($url);
            $content = iconv('cp1251', 'utf-8', $content);
            $file = "./tournament.csv";
            file_put_contents($file, $content);

            // Ищем команды, занявшие 1-10 место
            $file = fopen('./tournament.csv', 'r');
            $line = fgets ($file);  $line = fgets ($file);
            for ($i = 1; $i <= 10; $i++) {
                $line = fgets ($file);
                $line = explode(";",$line);
                $teams_top10[$i] = $line[0];
                if ($i == 1) { $winner_name = $line[1]; }
            }
            fclose($file);

            // Считываем полную таблицу в массив
            $file = fopen('./tournament.csv', 'r');
            while ($line = fgets ($file)) {
                $line = str_getcsv($line,';');
                $num_quest = max(array_keys($line))-3;
                for ($i = 1; $i <= $num_quest; $i++) {
                    $q_team[$line[0]][$line[3]][$i] = $line[$i+3];
                }
            @$num_tour = $line[3];
            }
            fclose($file);

            // Считаем сумму очков победителя и собственной команды
            $winner_sum = 0;
            for ($tour = 1; $tour <= $num_tour; $tour++) {
                for ($j = 1; $j <= $num_quest; $j++) {
                    $winner_sum = $winner_sum + $q_team[$teams_top10[1]][$tour][$j];
                }
            }
            $cmd_sum = 0;
            for ($tour = 1; $tour <= $num_tour; $tour++) {
                for ($j = 1; $j <= $num_quest; $j++) {
                    $cmd_sum = $cmd_sum + $q_team[$id_cmd][$tour][$j];
                }
            }
            $text = $text . "Победитель: $winner_name с суммой $winner_sum.\n";
            $text = $text . "$name_cmd заняла $tourPlace место с суммой $cmd_sum.\n\n";

            $counter = 0; // Сквозной счетчик вопросов
            $counter_2 = 0; // Еще один

            // Считаем сколько взяли в среднем по турам команды ТОП 10
            for ($tour = 1; $tour <= $num_tour; $tour++) {
                $quest_sum = 0;
                $quest_cmd = 0;
                for ($i = 1; $i <= 10; $i++) {
                    for ($j = 1; $j <= $num_quest; $j++) {
                        $quest_sum = $quest_sum + $q_team[$teams_top10[$i]][$tour][$j];
                    }
                }
                // И сколько взяла наша команда
                for ($j = 1; $j <= $num_quest; $j++) {
                    $quest_cmd = $quest_cmd + $q_team[$id_cmd][$tour][$j];
                }
                $quest_sum = $quest_sum / 10;
                $text = $text . "Тур номер $tour. В среднем взято $quest_sum. Взято $name_cmd_short: $quest_cmd\n";

                // Ищем в чем мы отличаемся от лидера
                $text_loser = "Взял лидер и не взяла $name_cmd_short: ";
                $text_winner = "Взяла $name_cmd_short и не взял лидер: ";
                for ($l = 1; $l <= $num_quest; $l++) {
                    $counter++;
                    if ($q_team[$id_cmd][$tour][$l] && !$q_team[$teams_top10[1]][$tour][$l]) {
                        $text_winner = $text_winner . $counter . " ";
                    }
                if (!$q_team[$id_cmd][$tour][$l] && $q_team[$teams_top10[1]][$tour][$l]) {
                    $text_loser = $text_loser . $counter . " ";
                    }
                }
                $text = $text . $text_winner . "\n";
                $text = $text . $text_loser . "\n";

                // Ищем гробы
                $text_grob = "";
                $counter_grob = 0;
                for ($l = 1; $l <= $num_quest; $l++) {
                    $counter_2++;
                    $is_grob = 1;
                    for ($i = 1; $i <= 10; $i++) {
                        if ($q_team[$teams_top10[$i]][$tour][$l]) { $is_grob = 0; }
                    }
                    if ($is_grob) {
                        $counter_grob++;
                        $text_grob = $text_grob . $counter_2 . " ";
                    }
                }
                if ($counter_grob) {
                    $text = $text . "Всего гробов в туре: $counter_grob. Конкретно: $text_grob\n\n";
                    } else {
                    $text = $text . "\n";
                }
            }
        }
    }

    $text = $text . "\nСерый рейтинг. Текущее место: $ratingNow_gray, прогноз: $ratingFuture_gray";
    $ratingDelta_gray = $ratingNow_gray - $ratingFuture_gray;
    if ($ratingDelta_gray > 0) { 
        $text = $text . ", дельта: +$ratingDelta_gray. Перегретая команда!\n";
        } else {
        $text = $text . ", дельта: $ratingDelta_gray. Украли победу!\n";
    }

    $text = $text . "Желтый рейтинг. Текущее место: $ratingNow_yellow, прогноз: $ratingFuture_yellow";
    $ratingDelta_yellow = $ratingNow_yellow - $ratingFuture_yellow;
    if ($ratingDelta_yellow > 0) { 
        $text = $text . ", дельта: +$ratingDelta_yellow. Перегретая команда!\n";
        } else {
        $text = $text . ", дельта: $ratingDelta_yellow. Украли победу!\n";
    }

    $text = $text . "\n" . $txt['bye'] . "\n";

    if ($send_mail && $cmd_sum > 0) { 
        $headers = "From: $mail_from_name <$mail_from_address>\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        mail($mail_to_address, $mail_subject, $text, $headers);
    };

    mysql_close($dbh);

    function getPage($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 ); 
        curl_setopt ($ch, CURLOPT_COOKIEJAR, 'COOKIE_FILE'); 
        curl_setopt ($ch, CURLOPT_COOKIEFILE, 'COOKIE_FILE'); 

        return curl_exec($ch);
    }

    function walkDom($node, $level = 0) {
        $indent = '';
        for ($i = 0; $i < $level; $i++)
        $indent .= '&nbsp;&nbsp;&nbsp;&nbsp;';
        if (true /*$node->nodeType == XML_TEXT_NODE*/) {
                    echo $indent.'<b>'.$node->nodeName.'</b> - |'.$node->nodeValue.'|';
                  if ( $node->nodeType == XML_ELEMENT_NODE ) {
                        $attributes = $node->attributes;
                        foreach($attributes as $attribute) {
                                echo ', '.$attribute->name.'='.$attribute->value;
                        }                                                    
                }
                  echo '<br />';
        }
     
        $cNodes = $node->childNodes;
        if (count($cNodes) > 0) {
                $level++ ;
                foreach($cNodes as $cNode)
                        walkDom($cNode, $level);
                $level = $level - 1;
        }
    }

    function prepareForDOM($html, $encoding) {
       $html = iconv($encoding, 'UTF-8//TRANSLIT', $html);
       $html = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1\b[^>]*>/is', '', $html);
       $tidy = new tidy;
       $config = array(
           'drop-font-tags' => true,
          'drop-proprietary-attributes' => true,
          'hide-comments' => true,
          'indent' => true,
          'logical-emphasis' => true,
          'numeric-entities' => true,
          'output-xhtml' => true,
          'wrap' => 0
      );
      $tidy->parseString($html, $config, 'utf8');
      $tidy->cleanRepair();
      $html = $tidy->value;
      $html = preg_replace('#<meta[^>]+>#isu', '', $html);
      $html = preg_replace('#<head\b[^>]*>#isu', "<head>\r\n<meta http-equiv='Content-Type' content='text/html; charset=utf-8' />", $html);

      return $html;
    };

    function domNodeToArray(DOMNode $node) {
     $ret = '';
 
      if ($node->hasChildNodes()) {
         if ($node->firstChild === $node->lastChild
             && $node->firstChild->nodeType === XML_TEXT_NODE
         ) {
             $ret = trim($node->nodeValue);
         } else {
                $ret = array();
                foreach ($node->childNodes as $child) {
                    if ($child->nodeType !== XML_TEXT_NODE) {
                        if (isset($ret[$child->nodeName])) {
                            if (!is_array($ret[$child->nodeName])
                               || !isset($ret[$child->nodeName][0])
                           ) {
                               $tmp = $ret[$child->nodeName];
                               $ret[$child->nodeName] = array();
                               $ret[$child->nodeName][] = $tmp;
                          }
    
                         $ret[$child->nodeName][] = domNodeToArray($child);
                        } else {
                          $ret[$child->nodeName] = domNodeToArray($child);
                        }
                  }
              }
           }
       }

       return $ret;
    }