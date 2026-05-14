<?php

/*
 * ==========================================================
 * ARTICLES.PHP
 * ==========================================================
 *
 * Articles page.
 * © 2017-2026 board.support. All rights reserved.
 *
 */

if (defined('SB_CROSS_DOMAIN') && SB_CROSS_DOMAIN) {
    header('Access-Control-Allow-Origin: *');
}
require_once('functions.php');
sb_cloud_load();
$query_category_id = sb_sanatize_string(sb_isset($_GET, 'category'), true);
$query_article_id = sb_sanatize_string(sb_isset($_GET, 'article_id'), true);
$query_search = sb_sanatize_string(sb_isset($_GET, 'search'), true);
$language_auto = sb_sanatize_string(sb_isset($_GET, 'lang', sb_get_user_language(false, true)), true);
$article = $query_article_id ? sb_get_articles($query_article_id, false, true, false, $language_auto) : false;
$language = $article && $article[0]['language'] ? $article[0]['language'] : $language_auto;
$code = '<div class="' . ($query_category_id ? 'sb-subcategories' : ($query_search ? 'sb-articles-search' : 'sb-grid sb-grid-3')) . '">';
$code_nav = '';
$code_left_nav = '';
$code_script = '';
$css = 'sb-articles-parent-categories-cnt';
$articles_page_url = sb_get_articles_page_url();
$articles_page_url_slash = $articles_page_url . (substr($articles_page_url, -1) == '/' ? '' : '/');
$url_rewrite = $articles_page_url && sb_is_articles_url_rewrite();
$is_left_nav = sb_get_setting('articles-left-menu');
$cloud_url_part = defined('ARTICLES_URL') && isset($_GET['chat_id']) ? sb_sanatize_string($_GET['chat_id'], true) . '/' : '';
$lang_attr = $language && ($language_auto != $language || isset($_GET['lang'])) ? 'lang=' . $language : '';
$code_breadcrumbs = $articles_page_url ? '<div class="sb-breadcrumbs"><a href="' . $articles_page_url . ($cloud_url_part ? '/' : '') . substr($cloud_url_part, 0, -1) . ($lang_attr ? '?' . $lang_attr : '') . '">' . sb_t('All categories', $language) . '</a>' : '';
if ($query_category_id) {
    $category = sb_get_article_category($query_category_id);
    if ($category) {
        $category = sb_get_article_category_language($category, $language, $query_category_id);
        $css = 'sb-articles-category-cnt';
        $image = sb_isset($category, 'image');
        if ($code_breadcrumbs) {
            $code_breadcrumbs .= '<i class="sb-icon-arrow-right"></i><a>' . $category['title'] . '</a></div>';
        }
        $code .= $code_breadcrumbs . '<div class="sb-parent-category-box">' . ($image ? '<img src="' . $image . '" />' : '') . '<div><h1>' . $category['title'] . '</h1><p>' . trim(sb_isset($category, 'description', '')) . '</p>' . '</div></div>';
        $articles = sb_get_articles(false, false, false, $query_category_id, $language);
        $articles_by_category = [];
        foreach ($articles as $article) {
            $category = sb_isset($article, 'category');
            $key = $category && $category != $query_category_id ? $category : '-';
            $articles_by_category_single = sb_isset($articles_by_category, $key, []);
            array_push($articles_by_category_single, $article);
            $articles_by_category[$key] = $articles_by_category_single;
        }
        foreach ($articles_by_category as $key => $articles) {
            $category = false;
            if ($key != '-') {
                $category = sb_get_article_category($key);
                $category = sb_get_article_category_language($category, $language, $key);
            }
            $code .= '<div class="sb-subcategory-box">' . ($category ? '<a href="' . ($url_rewrite ? $articles_page_url_slash . $cloud_url_part . 'category/' . $key . ($lang_attr ? '?' . $lang_attr : '') : $articles_page_url . '?category=' . $key . $cloud_url_part . ($lang_attr ? '&' . $lang_attr : '')) . '" class="sb-subcategory-title"><h2>' . $category['title'] . '</h2><p>' . trim(sb_isset($category, 'description', '')) . '</p></a>' : '') . '<div class="sb-subcategory-articles">';
            foreach ($articles as $article) {
                $code .= '<a class="sb-icon-arrow-right" href="' . sb_get_article_url($article) . '">' . $article['title'] . '</a>';
            }
            $code .= '</div></div>';
        }
    }
} else if ($query_article_id) {
    $css = 'sb-article-cnt';
    if ($article) {
        $article = $article[0];
        $lang_nav = sb_get_multi_setting('articles-language-settings', 'articles-language-nav');
        if ($lang_nav) {
            $article_languages = sb_get_article_languages(sb_isset($article, 'parent_id', $query_article_id));
            $lang_nav = '<div class="sb-lang-nav sb-select">';
            $lang_nav_ul = '';
            foreach ($article_languages as $article_language) {
                $label = '<img src="' . SB_URL . '/media/flags/' . $article_language['language'] . '.png" /> ' . strtoupper($article_language['language']);
                if ($article['id'] != $article_language['id']) {
                    $lang_nav_ul .= '<li><a href="' . sb_get_article_url($article_language) . '">' . $label . '</a></li>';
                } else {
                    $lang_nav .= '<p>' . $label . '</p>';
                }
            }
            $lang_nav .= '<ul' . (count($article_languages) > 8 ? ' class="sb-scroll-area"' : '') . '>' . $lang_nav_ul . '</ul></div>';
        }
        if ($is_left_nav) {
            $categories = sb_get_articles_categories('parent');
            if (!empty($categories)) {
                $code_left_nav = '<div class="sb-articles-list-nav"><div class="sb-scroll-area">';
                foreach ($categories as $category) {
                    $category = sb_get_article_category_language($category, $language, $category['id']);
                    $title = sb_isset($category, 'title');
                    $description = sb_isset($category, 'description');
                    $code_left_nav .= '<div><h2 class="sb-title">' . $title . '</h2><div>';
                    $articles = sb_get_articles(false, false, false, $category['id'], $language);
                    foreach ($articles as $article_) {
                        $code_left_nav .= '<a href="' . sb_get_article_url($article_) . '"' . ($article_['id'] == $query_article_id ? ' class="sb-active"' : '') . '>' . $article_['title'] . '</a>';
                    }
                    $code_left_nav .= '</div></div>';
                }
                $code_left_nav .= '</div></div>';
                $css .= ' sb-article-cnt-large';
            }
        }
        if ($code_breadcrumbs) {
            $article_categories = [sb_isset($article, 'parent_category'), sb_isset($article, 'category')];
            for ($i = 0; $i < 2; $i++) {
                if ($article_categories[$i]) {
                    $category = sb_get_article_category_language(sb_get_article_category($article_categories[$i]), $language, $article_categories[$i]);
                    $code_breadcrumbs .= '<i class="sb-icon-arrow-right"></i><a href="' . ($url_rewrite ? $articles_page_url_slash . $cloud_url_part . 'category/' . $article_categories[$i] . ($lang_attr ? '?' . $lang_attr : '') : $articles_page_url . '?category=' . $article_categories[$i] . $cloud_url_part . ($lang_attr ? '&' . $lang_attr : '')) . '">' . $category['title'] . '</a>';
                }
            }
            $code_breadcrumbs .= '<i class="sb-icon-arrow-right"></i><a>' . $article['title'] . '</a></div>';
        }
        $code = $code_breadcrumbs . '<div data-id="' . $article['id'] . '" class="sb-article"><div class="sb-title">' . $article['title'] . '</div>';
        $code .= '<div class="sb-content">' . $article['content'] . '</div>';
        if (!empty($article['link'])) {
            $code .= '<div class="sb-read-more-cnt"><a href="' . $article['link'] . '" target="_blank" class="sb-btn">' . sb_t('Read more', $language) . '</a></div>';
        }
        if (sb_get_multi_setting('articles-submit-ticket-button', 'articles-submit-ticket-button-active')) {
            $link = sb_get_multi_setting('articles-submit-ticket-button', 'articles-submit-ticket-button-link');
            if ($link) {
                $text = sb_get_multi_setting('articles-submit-ticket-button', 'articles-submit-ticket-button-text');
                $code .= '<div class="sb-button-cnt">' . ($text ? '<span>' . $text . '</span>' : '') . '<a href="' . $link . '" target="_blank" class="sb-btn-text">' . sb_t(sb_get_multi_setting('articles-submit-ticket-button', 'articles-submit-ticket-button-name', 'Read more'), $language) . '</a></div>';
            }
        }
        $code .= '<div class="sb-rating-ext' . ($lang_nav ? ' sb-lang-nav-cnt' : '') . '"><div class="sb-rating"><span>' . sb_t('Rate and review', $language) . '</span><div>';
        $code .= '<i data-rating="positive" class="sb-submit sb-icon-like"><span>' . sb_t('Helpful', $language) . '</span></i>';
        $code .= '<i data-rating="negative" class="sb-submit sb-icon-dislike"><span>' . sb_t('Not helpful', $language) . '</span></i>';
        $code .= '</div></div>' . $lang_nav . '</div>';
        $code_script = 'var SB_ARTICLE_ID = "' . $article['id'] . '";';
        $nav = json_decode($article['nav'], true);
        if ($nav) {
            $code_nav = '<div class="sb-articles-nav-mobile-btn">' . sb_('Table of contents') . '<i class="sb-icon-arrow-down"></i></div><div class="sb-scroll-area">';
            foreach ($nav as $nav_item) {
                $code_nav .= '<a href="#' . sb_string_slug($nav_item[0]) . '"' . ($nav_item[1] == 2 ? '' : ' class="sb-nav-sub"') . '>' . htmlspecialchars($nav_item[0]) . '</a>';
            }
            $code_nav .= '</div>';
        }
    }
} else if ($query_search) {
    $css = 'sb-article-search-cnt';
    $articles = sb_search_articles($query_search, $language);
    $count = count($articles);
    $code .= '<h2 class="sb-articles-search-title">' . sb_t('Search results for:', $language) . ' <span>' . $query_search . '</span></h2><div class="sb-search-results">';
    for ($i = 0; $i < $count; $i++) {
        $code .= '<a href="' . sb_get_article_url($articles[$i]) . '"><h3>' . $articles[$i]['title'] . '</h3><p>' . $articles[$i]['content'] . '</p></a>';
    }
    if (!$count) {
        $code .= '<p>' . sb_t('No results found.', $language) . '</p>';
    }
    $code .= '</div>';
} else {
    $categories = sb_get_articles_categories('parent');
    if (!empty($categories)) {
        foreach ($categories as $category) {
            $category = sb_get_article_category_language($category, $language, $category['id']);
            $image = sb_isset($category, 'image');
            $code .= '<div><a class="sb-subcategory-title" href="' . ($url_rewrite ? $articles_page_url_slash . $cloud_url_part . 'category/' . $category['id'] . ($lang_attr ? '?' . $lang_attr : '') : $articles_page_url . '?category=' . $category['id'] . $cloud_url_part . ($lang_attr ? '&' . $lang_attr : '')) . '">' . ($image ? '<img src="' . $image . '" />' : '') . '<h2>' . sb_isset($category, 'title') . '</h2><p>' . sb_isset($category, 'description') . '</p></a><div class="sb-subcategory-articles">';
            $articles = sb_get_articles(false, false, false, $category['id'], $language);
            foreach ($articles as $article) {
                $code .= '<a class="sb-icon-arrow-right" href="' . sb_get_article_url($article) . '">' . $article['title'] . '</a>';
            }
            $code .= '</div></div>';
        }
    } else {
        $code .= '<p>' . sb_t('No results found.', $language) . '</p>';
    }
}
if (sb_is_rtl()) {
    $css .= ' sb-rtl';
}
if ($is_left_nav) {
    $css .= ' sb-articles-cnt-large';
}
$code .= '</div>';

function sb_get_article_category_language($category, $language, $category_id) {
    if (isset($category['languages'][$language])) {
        $category_translation = $category['languages'][$language];
        $category['title'] = $category_translation['title'];
        $category['description'] = $category_translation['description'];
        return $category;
    }
    if (sb_get_multi_setting('google', 'google-multilingual-translation') && sb_get_multi_setting('articles-language-settings', 'articles-auto-translation')) {
        $translations = [$category['title']];
        if (isset($category['description'])) {
            array_push($translations, $category['description']);
        }
        $translations = sb_google_translate($translations, $language);
        if (!empty($translations[0])) {
            $category['title'] = $translations[0];
            $category['description'] = sb_isset($translations, 1, '');
            $articles_categories = sb_get_articles_categories();
            for ($i = 0; $i < count($articles_categories); $i++) {
                if ($articles_categories[$i]['id'] == $category_id) {
                    $articles_categories[$i]['languages'][$language] = ['title' => $category['title'], 'description' => $category['description']];
                    sb_save_articles_categories($articles_categories);
                }
            }
        }
    }
    return $category;
}

?>

<div class="sb-articles-page <?php echo $css ?>">
    <div class="sb-articles-header">
        <div>
            <h1>
                <?php sb_e(sb_get_setting('articles-title', 'Help Center')) ?>
            </h1>
            <div class="sb-input sb-input-btn">
                <input placeholder="<?php sb_e('Search for articles...') ?>" autocomplete="off" />
                <div class="sb-search-articles sb-icon-search" onclick="document.location.href = '<?php echo ($articles_page_url ? $articles_page_url : '') . (defined('ARTICLES_URL') && isset($_GET['chat_id']) ? (substr($articles_page_url, -1) == '/' ? '' : '/') . $_GET['chat_id'] . '/' : '') . '?search=' ?>' + $(this).prev().val()"></div>
            </div>
        </div>
    </div>
    <?php
    if ($query_article_id) {
        echo '<div class="sb-articles-cnt">' . $code_left_nav . '<div class="sb-articles-body">' . $code . '</div><div class="sb-articles-nav">' . $code_nav . '</div></div>';
    } else {
        echo '<div class="sb-articles-body">' . $code . '  </div>';
    }
    ?>
</div>
<?php sb_js_global() ?>
<script>
    <?php echo $code_script ?>
</script>
