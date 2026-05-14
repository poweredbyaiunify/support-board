<?php

/*
 * ==========================================================
 * REPORTS.PHP
 * ==========================================================
 *
 * Reports PHP functions file. © 2017-2026 board.support. All rights reserved.
 *
 * 1. Return the data of a report
 * 2. Return the report area code
 * 3. Update the values of a report
 * 4. Export the report in a CSV file
 *
 */

function sb_reports_get($report_name_or_category, $date_start = false, $date_end = false, $timezone = false) {
    $utc_offset = sb_utc_offset() * -1 * 3600;
    $reports = [];
    $reports_category_list = [
        'conversations' => ['new-conversations', 'missed-conversations', 'conversations-time'],
        'messages' => ['direct-messages', 'direct-emails', 'direct-sms', 'follow-up', 'message-automations', 'email-automations'],
        'users' => ['new-visitors', 'new-leads', 'new-users', 'registrations', 'countries', 'languages', 'browsers', 'os'],
        'agents' => ['agents-ratings', 'agents-response-time', 'agents-conversations', 'agents-conversations-time'],
        'articles' => ['articles-searches', 'articles-views', 'articles-views-single', 'articles-ratings'],
        'chatbot' => ['chatbot-conversations', 'chatbot-human-takeovers', 'chatbot-human-takeovers-percentage', 'chatbot-no-human-takeovers', 'chatbot-no-human-takeovers-percentage']
    ];

    // Set up date range
    $date = 'A.creation_time >= "' . sb_db_escape(date('Y-m-d', strtotime($date_start ? str_replace('/', '-', $date_start) : '-30 days'))) . ' 00:00" AND A.creation_time <= "' . sb_db_escape(date('Y-m-d', $date_end ? strtotime(str_replace('/', '-', $date_end)) : time())) . ' 23:59"';

    // Generate reports data
    $reports_list = sb_isset($reports_category_list, $report_name_or_category);
    if (!$reports_list) {
        foreach ($reports_category_list as $reports_category) {
            if (in_array($report_name_or_category, $reports_category)) {
                $reports_list = [$report_name_or_category];
                break;
            }
        }
    }
    if (!$reports_list) {
        return false;
    }
    foreach ($reports_list as $report_name) {
        $data = [];
        $data_final = [];
        $title = '';
        $table = [sb_('Date'), sb_('Count')];
        $description = '';
        $period = [];
        $query = '';
        $time_range = true;
        $label_type = 1;
        $chart_type = 'line';
        $average = 0;
        $average_label = sb_('Total');
        switch ($report_name) {
            case 'chatbot-no-human-takeovers-percentage':
            case 'chatbot-human-takeovers-percentage':
            case 'agents-ratings':
            case 'articles-ratings':
                $average_label = sb_('Average');
                $table[1] = sb_('Percentage');
                break;
            case 'conversations-time':
            case 'agents-conversations-time':
                $average_label = sb_('Average duration');
                $table[1] = sb_('Duration');
                break;
            case 'agents-response-time':
                $average_label = sb_('Average time');
                $table[1] = sb_('Time');
                break;
        }
        switch ($report_name) {
            case 'chatbot-no-human-takeovers-percentage':
            case 'chatbot-no-human-takeovers':
                $is_percentage = $report_name == 'chatbot-no-human-takeovers-percentage';
                $query = 'SELECT A.creation_time FROM sb_conversations A WHERE NOT EXISTS (SELECT 1 FROM sb_messages M WHERE M.conversation_id = A.id AND M.user_id IN (' . implode(',', sb_get_agents_ids()) . ') LIMIT 1)';
                $title = $is_percentage ? 'Percentage of chatbot conversations without human takeover' : 'Chatbot conversations without human takeover';
                $description = $is_percentage ? 'Percentage of conversations that include at least one chatbot message and no escalation to a human agent, compared to all conversations.' : 'Number of conversations that include at least one chatbot message and no escalation to a human agent.';
                break;
            case 'chatbot-human-takeovers-percentage':
            case 'chatbot-human-takeovers':
                $is_percentage = $report_name == 'chatbot-human-takeovers-percentage';
                $query = 'SELECT DISTINCT A.creation_time FROM sb_conversations A JOIN sb_messages M1 ON M1.conversation_id = A.id AND (M1.user_id IN (' . implode(',', sb_get_agents_ids()) . ') OR M1.payload LIKE "%{\"human-takeover\":true}%") JOIN sb_messages M2 ON M2.conversation_id = A.id AND M2.user_id = ' . sb_get_bot_ID() . ' AND M2.message NOT LIKE "%sb-follow-up%" AND M2.message NOT LIKE "%[timetable]%" AND M2.payload NOT LIKE "%{\"type\":\"close-message\"}%" AND M2.payload NOT LIKE "%{\"type\":\"welcome-message\"}%" AND M2.payload NOT LIKE "%{\"type\":\"privacy-message\"}%"';
                $title = $is_percentage ? 'Percentage of chatbot conversations with human takeover' : 'Chatbot conversations with human takeover';
                $description = $is_percentage ? 'Percentage of conversations that include at least one chatbot message and were escalated to a human agent, compared to all conversations.' : 'Number of conversations that include at least one chatbot message and were escalated to a human agent.';
                break;
            case 'chatbot-conversations':
                $query = 'SELECT A.creation_time FROM sb_conversations A JOIN (SELECT conversation_id, MIN(id) AS first_message_id FROM sb_messages WHERE user_id = ' . sb_get_bot_ID() . ' AND message NOT LIKE "%sb-follow-up%" AND message NOT LIKE "%[timetable]%" AND payload NOT LIKE "%{\"type\":\"close-message\"}%" AND payload NOT LIKE "%{\"type\":\"welcome-message\"}%" AND payload NOT LIKE "%{\"type\":\"privacy-message\"}%" GROUP BY conversation_id) M ON A.id = M.conversation_id';
                $title = 'Chatbot conversations';
                $description = 'Number of conversations that include at least one chatbot message.';
                break;
            case 'new-conversations':
                $query = 'SELECT A.creation_time FROM sb_conversations A, sb_users B WHERE B.id = A.user_id AND B.user_type <> "visitor"';
                $title = 'New conversations';
                $description = 'Number of new conversations initiated by users.';
                break;
            case 'missed-conversations':
                $query = 'SELECT A.creation_time FROM sb_conversations A WHERE NOT EXISTS (SELECT 1 FROM sb_messages B WHERE B.conversation_id=A.id AND B.user_id IN (' . implode(',', sb_get_agents_ids()) . '))' . (sb_is_chatbot_active() ? ' AND EXISTS (SELECT 1 FROM sb_messages C WHERE C.conversation_id=A.id AND C.payload LIKE "%{\"human-takeover\":true}%")' : '');
                $title = 'Missed conversations';
                $description = 'Number of conversations without a reply from a human agent. If chatbot human takeover is active, only conversations that were escalated to an agent are counted.';
                break;
            case 'conversations-time':
                $query = 'SELECT creation_time, conversation_id, payload FROM sb_messages A';
                $title = 'Average conversation duration';
                $description = 'Average duration of conversations. Messages sent more than 7 days after the previous message, or after the conversation has been archived, are treated as a new conversation.';
                $table = [sb_('Date'), sb_('Average duration')];
                $label_type = 2;
                break;
            case 'agents-conversations-time':
                $query = 'SELECT creation_time, conversation_id, payload FROM sb_messages A';
                $title = 'Average agent conversation duration';
                $description = 'Average duration of each agent\'s conversations (average resolution time). Messages sent more than 7 days after the previous message, or after the conversation has been archived, are counted as part of a new conversation. If chatbot human takeover is active, response time is calculated only after the takeover.';
                $table = [sb_('Agent name'), sb_('Average duration')];
                $label_type = 2;
                $time_range = false;
                break;
            case 'new-visitors':
                $query = 'SELECT creation_time, value FROM sb_reports A WHERE name = "visitors"';
                $title = 'New visitors';
                $description = 'Number of users who have not started any conversations and are not registered users.';
                break;
            case 'new-leads':
                $query = 'SELECT creation_time FROM sb_users A WHERE user_type = "lead"';
                $title = 'New leads';
                $description = 'Number of users who have started at least one conversation but are not registered.';
                break;
            case 'new-users':
                $query = 'SELECT creation_time FROM sb_users A WHERE user_type = "user"';
                $title = 'New users';
                $description = 'Number of users registered with an email address.';
                break;
            case 'agents-response-time':
                $title = 'Average agent response time';
                $description = 'Average time for agents to send the first reply after a user\'s initial message. If chatbot human takeover is active, response time is calculated only after the takeover.';
                $table = [sb_('Agent name'), sb_('Average time')];
                $time_range = false;
                $label_type = 2;
                break;
            case 'agents-conversations':
                $title = 'Agent conversations';
                $description = 'Number of conversations with at least one reply from an agent.';
                $table = [sb_('Agent name'), sb_('Number of conversations')];
                $time_range = false;
                break;
            case 'agents-ratings':
                $title = 'Agent ratings';
                $description = 'Ratings given to agents by users.';
                $table = [sb_('Agent name'), sb_('Ratings')];
                $chart_type = 'horizontalBar';
                $time_range = false;
                $label_type = 3;
                break;
            case 'countries':
                $title = 'User countries';
                $description = 'Countries of users who have started at least one conversation.';
                $table = [sb_('Country'), sb_('Count')];
                $time_range = false;
                $chart_type = 'pie';
                $label_type = 4;
                break;
            case 'languages':
                $title = 'User languages';
                $description = 'Languages of users who have started at least one conversation.';
                $table = [sb_('Language'), sb_('Count')];
                $time_range = false;
                $chart_type = 'pie';
                $label_type = 4;
                break;
            case 'browsers':
                $title = 'User browsers';
                $description = 'Browsers used by users who have started at least one conversation.';
                $table = [sb_('Browser'), sb_('Count')];
                $time_range = false;
                $chart_type = 'pie';
                $label_type = 4;
                break;
            case 'os':
                $title = 'User operating systems';
                $description = 'Operating systems used by users who have started at least one conversation.';
                $table = [sb_('Operating system'), sb_('Count')];
                $time_range = false;
                $chart_type = 'pie';
                $label_type = 4;
                break;
            case 'follow-up':
                $query = 'SELECT creation_time, value FROM sb_reports A WHERE name = "follow-up"';
                $title = 'Follow-up emails';
                $description = 'Number of users who registered their email through a follow-up message.';
                break;
            case 'registrations':
                $query = 'SELECT creation_time, value FROM sb_reports A WHERE name = "registrations"';
                $title = 'New registrations';
                $description = 'Number of users who created an account using the chat registration form.';
                break;
            case 'articles-searches':
                $query = 'SELECT creation_time, value FROM sb_reports A WHERE name = "articles-searches"';
                $title = 'Article searches';
                $description = 'Number of article searches performed by users.';
                $table = [sb_('Date'), sb_('Search terms')];
                break;
            case 'articles-ratings':
                $query = 'SELECT value, extra FROM sb_reports A WHERE name = "article-ratings"';
                $title = 'Article ratings';
                $description = 'Ratings given to articles by users.';
                $table = [sb_('Article name'), sb_('Ratings')];
                $chart_type = 'horizontalBar';
                $time_range = false;
                $label_type = 3;
                break;
            case 'articles-views-single':
            case 'articles-views':
                $query = 'SELECT creation_time, value, extra FROM sb_reports A WHERE name = "articles-views"';
                $title = 'Article views';
                $description = 'Number of times articles were viewed by users.';
                if ($report_name == 'articles-views-single') {
                    $title = 'Article views by article';
                    $chart_type = 'horizontalBar';
                    $time_range = false;
                    $table = [sb_('Article'), sb_('Count')];
                }
                break;
            case 'sms-automations':
            case 'email-automations':
            case 'message-automations':
                $query = 'SELECT creation_time, value FROM sb_reports A WHERE name = "' . $report_name . '"';
                $title = $description = sb_string_slug($report_name, 'string');
                break;
            case 'direct-sms':
            case 'direct-emails':
            case 'direct-messages':
                $query = 'SELECT creation_time, value FROM sb_reports A WHERE name = "' . $report_name . '"';
                $name = $report_name == 'direct-emails' ? 'emails' : ($report_name == 'direct-messages' ? 'chat messages' : 'text messages');
                $title = 'Direct ' . $name;
                $description = 'Direct messages sent to users. The details column shows the first part of each message and the number of users it was sent to.';
                $table = [sb_('Date'), sb_('Details')];
                break;
        }
        switch ($report_name) {
            case 'sms-automations':
            case 'email-automations':
            case 'message-automations':
            case 'registrations':
            case 'follow-up':
            case 'new-users':
            case 'new-leads':
            case 'new-visitors':
            case 'new-conversations':
            case 'missed-conversations':
            case 'chatbot-conversations':
            case 'chatbot-human-takeovers':
            case 'chatbot-human-takeovers-percentage':
            case 'chatbot-no-human-takeovers':
            case 'chatbot-no-human-takeovers-percentage':
                $rows = sb_db_get($query . ($date ? ' AND ' . $date : '') . ' ORDER BY STR_TO_DATE(A.creation_time, "%Y-%m-%d %T")', false);
                $sum = !in_array($report_name, ['new-visitors', 'follow-up', 'registrations', 'message-automations', 'email-automations', 'sms-automations']);
                for ($i = 0; $i < count($rows); $i++) {
                    $date_row = date('d/m/Y', strtotime($rows[$i]['creation_time']) + $utc_offset);
                    $data[$date_row] = $sum ? [empty($data[$date_row]) ? 1 : $data[$date_row][0] + 1] : [$rows[$i]['value']];
                    $average += $sum || empty($rows[$i]['value']) ? 1 : $rows[$i]['value'];
                }
                break;
            case 'agents-conversations-time':
            case 'conversations-time':
                $rows = sb_db_get($query . ($date ? ' WHERE ' . $date : '') . ' ORDER BY STR_TO_DATE(creation_time, "%Y-%m-%d %T")', false);
                $count = count($rows);
                if ($count) {
                    $last_id = $rows[0]['conversation_id'];
                    $first_time = $rows[0]['creation_time'];
                    $times = [];
                    $is_agents_time = $report_name == 'agents-conversations-time';
                    for ($i = 1; $i < $count; $i++) {
                        $message = $rows[$i];
                        $time = $message['creation_time'];
                        if (str_contains($message['payload'], '"human-takeover":true')) {
                            $first_time = $message['creation_time'];
                        }
                        if (($message['conversation_id'] != $last_id) || str_contains($message['payload'], 'conversation-status-update-3') || strtotime('+7 day', strtotime($first_time)) < strtotime($time) || $i == ($count - 1)) {
                            $last_time = strtotime($message['creation_time']);
                            array_push($times, [$is_agents_time ? $last_id : date('d/m/Y', $last_time), $last_time - strtotime($first_time)]);
                            $first_time = $time;
                            $last_id = $message['conversation_id'];
                        }
                    }
                    if ($is_agents_time) {
                        $agents_counts = [];
                        $agents_conversations = [];
                        $rows = sb_db_get('SELECT conversation_id, first_name, last_name FROM sb_messages A, sb_users B WHERE A.user_id = B.id AND (B.user_type = "agent" OR  B.user_type = "admin") GROUP BY conversation_id', false);
                        for ($i = 0; $i < count($rows); $i++) {
                            $agents_conversations[$rows[$i]['conversation_id']] = sb_get_user_name($rows[$i]);
                        }
                        for ($i = 0; $i < count($times); $i++) {
                            if (isset($agents_conversations[$times[$i][0]])) {
                                $name = $agents_conversations[$times[$i][0]];
                                $data[$name] = empty($data[$name]) ? $times[$i][1] : $data[$name] + $times[$i][1];
                                $agents_counts[$name] = empty($agents_counts[$name]) ? 1 : $agents_counts[$name] + 1;
                            }
                        }
                        foreach ($data as $key => $value) {
                            $average_ = intval($value / $agents_counts[$key]);
                            $data[$key] = [$average_, gmdate('H:i:s', $average_)];
                            $average += $average_;
                        }
                        $average = gmdate('H:i:s', intval($average / count(array_keys($data))));
                    } else {
                        for ($i = 0; $i < count($times); $i++) {
                            $time = $times[$i][0];
                            $count = 0;
                            $sum = 0;
                            if (!isset($data[$time])) {
                                for ($y = 0; $y < count($times); $y++) {
                                    if ($times[$y][0] == $time) {
                                        $sum += $times[$y][1];
                                        $count++;
                                    }
                                }
                                $seconds = intval($sum / $count);
                                $data[$time] = [$seconds, gmdate('H:i:s', $seconds)];
                                $average += $seconds;
                            }
                        }
                        $average = gmdate('H:i:s', intval($average / count(array_keys($data))));
                    }
                }
                break;
            case 'agents-conversations':
                $rows = sb_db_get('SELECT first_name, last_name FROM sb_messages A, sb_users B WHERE A.user_id = B.id AND (B.user_type = "agent" OR  B.user_type = "admin") ' . ($date ? ' AND ' . $date : '') . ' GROUP BY conversation_id, B.id', false);
                for ($i = 0; $i < count($rows); $i++) {
                    $name = sb_get_user_name($rows[$i]);
                    $data[$name] = [empty($data[$name]) ? 1 : $data[$name][0] + 1];
                    $average++;
                }
                break;
            case 'agents-response-time':
                $conversation_messages = sb_db_get('SELECT A.user_id, B.user_type, A.conversation_id, A.payload, A.creation_time FROM sb_messages A, sb_users B WHERE B.id = A.user_id AND A.conversation_id IN (SELECT conversation_id FROM sb_messages A WHERE user_id IN (SELECT id FROM sb_users WHERE user_type = "agent" OR user_type = "admin") ' . ($date ? ' AND ' . $date : '') . ') ORDER BY A.conversation_id, STR_TO_DATE(A.creation_time, "%Y-%m-%d %T")', false);
                $count = count($conversation_messages);
                if ($count) {
                    $agents = [];
                    $active_conversation = $conversation_messages[0];
                    $skip = false;
                    $agents_ids = '';
                    for ($i = 1; $i < $count; $i++) {
                        $message = $conversation_messages[$i];
                        if ($skip) {
                            if ($active_conversation['conversation_id'] != $message['conversation_id']) {
                                $active_conversation = $message;
                                $skip = false;
                            }
                            continue;
                        }
                        if (str_contains($message['payload'], '"human-takeover":true')) {
                            $active_conversation = $message;
                        }
                        if (sb_is_agent($message, true)) {
                            $conversation_time = strtotime($message['creation_time']) - strtotime($active_conversation['creation_time']);
                            $agent_id = $message['user_id'];
                            if (!isset($agents[$agent_id])) {
                                $agents[$agent_id] = [];
                                $agents_ids .= $agent_id . ',';
                            }
                            array_push($agents[$agent_id], $conversation_time);
                            $skip = true;
                        }
                    }
                    $rows = sb_db_get('SELECT id, first_name, last_name FROM sb_users WHERE id IN (' . substr($agents_ids, 0, -1) . ')', false);
                    $agent_names = [];
                    for ($i = 0; $i < count($rows); $i++) {
                        $agent_names[$rows[$i]['id']] = sb_get_user_name($rows[$i]);
                    }
                    foreach ($agents as $key => $times) {
                        $sum = 0;
                        $count = count($times);
                        for ($i = 0; $i < $count; $i++) {
                            $sum += $times[$i];
                        }
                        $average_ = intval($sum / $count);
                        $data[$agent_names[$key]] = [$average_, gmdate('H:i:s', $average_)];
                        $average += $average_;
                    }
                    $average = gmdate('H:i:s', intval($average / count(array_keys($agents))));
                }
                break;
            case 'articles-ratings':
            case 'agents-ratings':
                $article = $report_name == 'articles-ratings';
                $ratings = $article ? sb_db_get($query, false) : sb_get_external_setting('ratings');
                if ($ratings) {
                    $rows = $article ? sb_get_articles() : sb_db_get('SELECT id, first_name, last_name FROM sb_users WHERE user_type = "agent" OR user_type = "admin"', false);
                    $items = [];
                    for ($i = 0; $i < count($rows); $i++) {
                        $items[$rows[$i]['id']] = $article ? $rows[$i]['title'] : sb_get_user_name($rows[$i]);
                    }
                    if ($article) {
                        for ($i = 0; $i < count($ratings); $i++) {
                            $rating = $ratings[$i];
                            if (isset($rating['extra'])) {
                                $id = $rating['extra'];
                                if (isset($items[$id]) && !empty($rating['value'])) {
                                    $article_ratings = json_decode($rating['value']);
                                    $positives = 0;
                                    $negatives = 0;
                                    $name = strlen($items[$id]) > 40 ? substr($items[$id], 0, 40) . '...' : $items[$id];
                                    for ($y = 0; $y < count($article_ratings); $y++) {
                                        $positives += $article_ratings[$y] == 1 ? 1 : 0;
                                        $negatives += $article_ratings[$y] == 1 ? 0 : 1;
                                    }
                                    $data[$name] = [$positives, $negatives];
                                }
                            }
                        }
                    } else {
                        foreach ($ratings as $rating) {
                            if (isset($rating['agent_id'])) {
                                $id = $rating['agent_id'];
                                if (isset($items[$id])) {
                                    $positive = $rating['rating'] == 1 ? 1 : 0;
                                    $negative = $rating['rating'] == 1 ? 0 : 1;
                                    $name = $items[$id];
                                    $data[$name] = isset($data[$name]) ? [$data[$name][0] + $positive, $data[$name][1] + $negative] : [$positive, $negative];
                                }
                            }
                        }
                    }
                    $average_list = [];
                    foreach ($data as $key => $value) {
                        $positive = $value[0];
                        $negative = $value[1];
                        $average_ = round($positive / ($negative + $positive) * 100, 2);
                        $data[$key] = [[$average_, round(100 - $average_, 2)], '<i class="sb-icon-like"></i>' . $positive . ' (' . $average_ . '%) <i class="sb-icon-dislike"></i>' . $negative];
                        array_push($average_list, $average_);
                    }
                    $average = '<i class="sb-icon-like"></i> ' . sb_reports_calculate_percentage($average_list);
                }
                break;
            case 'articles-views':
            case 'articles-views-single':
                $rows = sb_db_get($query . ($date ? ' AND ' . $date : '') . ' ORDER BY STR_TO_DATE(A.creation_time, "%Y-%m-%d %T")', false);
                $single = $report_name == 'articles-views-single';
                for ($i = 0; $i < count($rows); $i++) {
                    $date_row = $single ? $rows[$i]['extra'] : date('d/m/Y', strtotime($rows[$i]['creation_time']) + $utc_offset);
                    $data[$date_row] = [intval($rows[$i]['value']) + (empty($data[$date_row]) ? 0 : $data[$date_row][0])];
                    $average++;
                }
                if ($single) {
                    $articles = sb_get_articles();
                    $data_names = [];
                    for ($i = 0; $i < count($articles); $i++) {
                        $id = sb_isset($articles[$i], 'id');
                        if ($id && isset($data[$id])) {
                            $article_title = $articles[$i]['title'];
                            $data_names[strlen($article_title) > 40 ? substr($article_title, 0, 40) . '...' : $article_title] = $data[$id];
                        }
                    }
                    $data = $data_names;
                }
                break;
            case 'os':
            case 'browsers':
            case 'languages':
            case 'countries':
                $field = 'location';
                $is_languages = $report_name == 'languages';
                $is_browser = $report_name == 'browsers';
                $is_os = $report_name == 'os';
                $is_country = $report_name == 'countries';
                if ($is_languages) {
                    $field = 'browser_language';
                } else if ($is_browser) {
                    $field = 'browser';
                } else if ($is_os) {
                    $field = 'os';
                }
                $language_codes = sb_get_json_resource('languages/language-codes.json');
                $country_codes = $is_country ? sb_get_json_resource('json/countries.json') : false;
                $rows = sb_db_get('SELECT value FROM sb_users_data WHERE slug = "' . $field . '" AND user_id IN (SELECT id FROM sb_users A WHERE (user_type = "lead" OR user_type = "user")' . ($date ? ' AND ' . $date : '') . ')', false);
                $total = 0;
                $flags = [];
                for ($i = 0; $i < count($rows); $i++) {
                    $value = $rows[$i]['value'];
                    $valid = false;
                    if ($is_country && strpos($value, ',')) {
                        $value = trim(substr($value, strpos($value, ',') + 1));
                        $valid = true;
                    }
                    if (($is_languages && isset($language_codes[strtolower($value)])) || ($is_country && isset($country_codes[strtoupper($value)]))) {
                        $code = strtolower($is_languages ? $value : $country_codes[strtoupper($value)]);
                        $value = $language_codes[$code];
                        if (!isset($flags[$value]) && file_exists(SB_PATH . '/media/flags/' . $code . '.png')) {
                            $flags[$value] = $code;
                        }
                        $valid = true;
                    }
                    if ($valid || $is_browser || $is_os) {
                        $data[$value] = empty($data[$value]) ? 1 : $data[$value] + 1;
                        $total++;
                    }
                }
                arsort($data);
                foreach ($data as $key => $value) {
                    $image = '';
                    if (isset($flags[$key]))
                        $image = '<img class="sb-flag" src="' . SB_URL . '/media/flags/' . $flags[$key] . '.png" />';
                    if ($is_browser) {
                        $lowercase = strtolower($key);
                        if (str_contains($lowercase, 'chrome')) {
                            $image = 'chrome';
                        } else if (str_contains($lowercase, 'edge')) {
                            $image = 'edge';
                        } else if (str_contains($lowercase, 'firefox')) {
                            $image = 'firefox';
                        } else if (str_contains($lowercase, 'opera')) {
                            $image = 'opera';
                        } else if (str_contains($lowercase, 'safari')) {
                            $image = 'safari';
                        }
                        if ($image)
                            $image = '<img src="' . SB_URL . '/media/devices/' . $image . '.svg" />';
                    }
                    if ($is_os) {
                        $lowercase = strtolower($key);
                        if (str_contains($lowercase, 'windows')) {
                            $image = 'windows';
                        } else if (str_contains($lowercase, 'mac') || str_contains($lowercase, 'apple') || str_contains($lowercase, 'ipad') || str_contains($lowercase, 'iphone')) {
                            $image = 'apple';
                        } else if (str_contains($lowercase, 'android')) {
                            $image = 'android';
                        } else if (str_contains($lowercase, 'linux')) {
                            $image = 'linux';
                        } else if (str_contains($lowercase, 'ubuntu')) {
                            $image = 'ubuntu';
                        }
                        if ($image)
                            $image = '<img src="' . SB_URL . '/media/devices/' . $image . '.svg" />';
                    }
                    $data[$key] = [$value, $image . $value . ' (' . round($value / $total * 100, 2) . '%)'];
                }
                break;
            case 'direct-sms':
            case 'direct-emails':
            case 'direct-messages':
            case 'articles-searches':
                $rows = sb_db_get($query . ($date ? ' AND ' . $date : '') . ' ORDER BY STR_TO_DATE(A.creation_time, "%Y-%m-%d %T")', false);
                for ($i = 0; $i < count($rows); $i++) {
                    $date_row = date('d/m/Y', strtotime($rows[$i]['creation_time']) + $utc_offset);
                    $search = '<div>' . $rows[$i]['value'] . '</div>';
                    $data[$date_row] = empty($data[$date_row]) ? [1, $search] : [$data[$date_row][0] + 1, $data[$date_row][1] . $search];
                    $average++;
                }
                break;
        }

        // Generate all days, months, years within the date range
        if (count($data)) {
            if ($time_range) {
                if ($timezone) {
                    date_default_timezone_set($timezone);
                }
                $period = new DatePeriod(new DateTime(date('Y-m-d', strtotime(str_replace('/', '-', $date_start ? $date_start : array_key_first($data))))), new DateInterval('P1D'), new DateTime(date('Y-m-d', strtotime(str_replace('/', '-', $date_end ? $date_end : array_key_last($data))) + 86400)));
                $period = iterator_to_array($period);
                $period_count = count($period);
                $date_format = $period_count > 730 ? 'Y' : ($period_count > 62 ? 'm/Y' : 'm/d/Y');
                $is_array = count(reset($data)) > 1;
                $counts = [];
                for ($i = 0; $i < $period_count; $i++) {
                    $key = $period[$i]->format($date_format);
                    if ($period_count < 62) {
                        $key = sb_beautify_date($key, true);
                    }
                    $key_original = $period[$i]->format('d/m/Y');
                    $value = empty($data[$key_original]) ? 0 : $data[$key_original][0];
                    $data_final[$key] = [empty($data_final[$key]) ? $value : $data_final[$key][0] + $value];
                    if ($label_type == 2) {
                        $counts[$key] = empty($counts[$key]) ? 1 : $counts[$key] + 1;
                    }
                    if ($is_array) {
                        array_push($data_final[$key], empty($data[$key_original][1]) ? '' : $data[$key_original][1]);
                    }
                }
                if ($label_type == 2 && $period_count > 62) {
                    foreach ($data_final as $key => $value) {
                        $data_final[$key] = [intval($value[0] / $counts[$key]), gmdate('H:i:s', intval($value[0] / $counts[$key]))];
                    }
                }
                $report_name_secondary = sb_isset(['chatbot-human-takeovers-percentage' => 'chatbot-conversations', 'chatbot-no-human-takeovers-percentage' => 'chatbot-conversations'], $report_name);
                if ($report_name_secondary) {
                    $data_secondary = sb_reports_get($report_name_secondary, $date_start, $date_end, $timezone)[$report_name_secondary];
                    $weighted_sum = 0;
                    $total_conversations = 0;
                    $average_list = [];
                    foreach ($data_final as $key => $value) {
                        $conversations = empty($data_secondary['data'][$key]) ? 0 : $data_secondary['data'][$key][0];
                        $takeover = $value[0];
                        $weighted_sum += $takeover;
                        $total_conversations += $conversations;
                        $percentage = $conversations ? round($takeover / $conversations * 100, 2) : 0;
                        $data_final[$key] = [$percentage, $percentage . '%'];
                        $average_list[] = $percentage;
                    }
                    $average = ($total_conversations ? round($weighted_sum / $total_conversations * 100, 2) : 0) . '%';
                }
            } else {
                $data_final = $data;
            }
        }
        $reports[$report_name] = ['title' => sb_($title), 'description' => sb_($description), 'data' => $data_final, 'table' => $table, 'table_inverse' => $time_range, 'label_type' => $label_type, 'chart_type' => $chart_type, 'average' => $average, 'average_label' => $average_label];
    }
    return $reports;
}

function sb_reports_get_code($report_id, $date_start = false, $date_end = false, $timezone = false) {
    $reports = sb_reports_get($report_id, $date_start, $date_end, $timezone);
    $code = '';
    $code_tooltip = '<i class="sb-icon-next sb-btn-icon sb-open-report" data-sb-tooltip="' . sb_('Open report') . '"></i>';
    foreach ($reports as $report_name => $report) {
        if (!empty($report['data'])) {
            $code .= '<div class="sb-report-block" data-report-name="' . $report_name . '"><div class="sb-report-block-header"><div><span>' . $report['title'] . ' <i class="sb-icon-help" data-sb-tooltip="' . $report['description'] . '"></i></span><span>' . (empty($report['average']) ? '' : $report['average']) . '</span></div>' . $code_tooltip . '</div><div class="sb-chart"><canvas></canvas></div></div>';
        }
    }
    return ['code' => $code ? '<div class="sb-grid">' . $code . '</div>' : '<div class="sb-no-results">' . sb_('There isn\'t enough data to display any reports.') . '</div>', 'reports' => $reports];
}

function sb_reports_update($name, $value = false, $external_id = false, $extra = false) {
    if (sb_get_multi_setting('performance', 'performance-reports')) {
        return false;
    }
    $now = gmdate('Y-m-d');
    $name = sb_db_escape($name);
    $extra = sb_db_escape($extra);
    switch ($name) {
        case 'direct-sms':
        case 'direct-emails':
        case 'direct-messages':
        case 'articles-searches':
            return sb_db_query('INSERT INTO sb_reports (name, value, creation_time, external_id, extra) VALUES ("' . $name . '", "' . sb_db_escape($value) . '", "' . $now . '", NULL, NULL)');
        case 'articles-views':
            $where = ' WHERE name = "articles-views" AND extra = "' . $extra . '" AND creation_time = "' . $now . '"';
            $count = sb_db_get('SELECT value FROM sb_reports' . $where . ' LIMIT 1');
            return sb_db_query(empty($count) ? 'INSERT INTO sb_reports (name, value, creation_time, external_id, extra) VALUES ("' . $name . '", 1, "' . $now . '", NULL, "' . $extra . '")' : 'UPDATE sb_reports SET value = ' . (intval($count['value']) + 1) . $where);
        default:
            $where = ' WHERE name = "' . $name . '" AND creation_time = "' . $now . '"';
            $count = sb_db_get('SELECT value FROM sb_reports' . $where . ' LIMIT 1');
            return sb_db_query(empty($count) ? 'INSERT INTO sb_reports (name, value, creation_time, external_id, extra) VALUES ("' . $name . '", 1, "' . $now . '", ' . ($external_id === false ? 'NULL' : '"' . $external_id . '"') . ', ' . ($extra === false ? 'NULL' : '"' . $extra . '"') . ')' : 'UPDATE sb_reports SET value = ' . (intval($count['value']) + 1) . $where);
    }
}

function sb_reports_export($report_name, $date_start = false, $date_end = false, $timezone = false) {
    if ($timezone) {
        date_default_timezone_set($timezone);
    }
    $report = sb_isset(sb_reports_get($report_name, $date_start, $date_end, $timezone), $report_name);
    if ($report) {
        $data = sb_isset($report, 'data', []);
        $rows = [];
        if ($report_name == 'agents-ratings') {
            $report['table'] = [$report['table'][0], sb_('Positive'), sb_('Positive percentage'), sb_('Negative')];
            foreach ($data as $key => $value) {
                $ratings = explode('<i class="sb-icon-dislike"></i>', $value[1]);
                $ratings[0] = str_replace('<i class="sb-icon-like"></i>', '', $ratings[0]);
                $ratings[0] = substr($ratings[0], 0, strpos($ratings[0], '('));
                array_push($rows, [$key, $ratings[0], $value[0], $ratings[1]]);
            }
        } else if ($report_name == 'agents-availability') {
            $report['table'] = [$report['table'][0], sb_('Date'), sb_('Intervals')];
            foreach ($data as $key => $value) {
                foreach ($value[1] as $date => $intervals) {
                    array_push($rows, [$key, $date, $intervals]);
                }
            }
        } else {
            foreach ($data as $key => $value) {
                $value = $value[count($value) - 1];
                if (strpos($value, ' />')) {
                    $value = substr($value, strpos($value, '/>') + 2);
                }
                array_push($rows, [$key, $value]);
            }
        }
        return sb_csv($rows, $report['table'], 'report-' . rand(100000, 999999999));
    }
    return false;
}

function sb_reports_calculate_percentage($numbers) {

    if (empty($numbers)) {
        return '0%';
    }
    $average = array_sum($numbers) / count($numbers);
    return round($average, 2) . '%';
}

?>