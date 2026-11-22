<?php

// New additions to this language file may have English placeholder values.
// These are marked with "ENGLISH PLACEHOLDER" and should be reviewed by a native speaker.

return [

    'name' => 'Polski',

    // -------------------------------------------------------------------------
    // Date localisation
    // Full names must match what PHP's date('F') and date('l') produce.
    // Short names must match date('M') and date('D').
    // -------------------------------------------------------------------------
    'date' => [
        'months' => [
            'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
            'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień',
        ],
        'months_short' => [
            'Sty', 'Lut', 'Mar', 'Kwi', 'Maj', 'Cze',
            'Lip', 'Sie', 'Wrz', 'Paź', 'Lis', 'Gru',
        ],
        'days' => [
            'Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota',
        ],
        'days_short' => [
            'Nie', 'Pon', 'Wto', 'Śro', 'Czw', 'Pią', 'Sob',
        ],
    ],

    // -------------------------------------------------------------------------
    // Frontend (public-facing)
    // -------------------------------------------------------------------------
    'frontend' => [
        'nav_home'              => 'Start',
        'site_title_fallback'   => 'Strona',
        'pagination_newer'      => '⬅ Nowsze posty',
        'pagination_older'      => 'Starsze posty ➡',
        'no_posts'              => 'Brak postów, bierz się do pisania! 🙃',
        'prev_post'             => '⬅ Poprzedni post',
        'next_post'             => 'Następny post ➡',
        'post_not_found'        => 'Post nie istnieje',
        'post_not_found_detail' => 'Post, o który prosiłeś, nie istnieje.',
        'search_label'          => 'Szukaj postów',
        'search_placeholder'    => 'Szukaj postów...',
        'search_button'         => 'Szukaj',
        'search_empty'          => 'Wprowadź frazę wyszukiwania postów.',
        'no_posts_found'        => 'Nie znaleziono postów dla "{search}".',
        'search_result'         => '{n} wynik wyszukiwania.',
        'search_results'        => '{n} wyników wyszukiwania.',
        'page_not_found'        => 'Strona nie istnieje.',
        'page_not_found_detail' => 'Żądana strona nie istnieje.',
        'no_tag_selected'       => 'Nie wybrano tagów.',
        'no_posts_for_tag'      => 'Nie znaleziono postów dla wybranego tagu.',
        'edit_post'             => 'Edit post', // ENGLISH PLACEHOLDER
        'edit_page'             => 'Edit page', // ENGLISH PLACEHOLDER
        'reading_time'          => '{n} min read', // ENGLISH PLACEHOLDER
    ],

    // -------------------------------------------------------------------------
    // Admin
    // -------------------------------------------------------------------------
    'admin' => [

        'nav' => [
            'dashboard'     => 'Panel sterowania',
            'content'       => 'Zawartość',
            'settings'      => 'Ustawienia',
            'view_site'     => 'Podgląd strony',
            'clear_cache'   => 'Wyczyść cache',
            'log_out'       => 'Wyloguj',
            'cache_cleared' => 'Cache wyczyszczony.',
            'invalid_action'=> 'Nieprawidłowe działanie admina.',
            'images'        => 'Images', // ENGLISH PLACEHOLDER
        ],

        'login' => [
            'page_title'     => 'Logowanie Admina - Pure Blog',
            'heading'        => 'Logowanie Admina',
            'username'       => 'Użytkownik',
            'password'       => 'Hasło',
            'remember_me'    => 'Zapamiętaj mnie',
            'submit'         => 'Zaloguj',
            'error_invalid'  => 'Nieprawidłowy użytkownik lub hasło.',
            'error_lockout'  => 'Zbyt dużo nieudanych prób. Spróbuj ponownie za {minutes} minut.',
            'error_lockout_5'=> 'Zbyt dużo nieudanych prób. Spróbuj ponownie za 5 minut.',
            'mfa_page_title' => 'Uwierzytelnianie dwuskładnikowe - Pure Blog',
            'mfa_heading'    => 'Uwierzytelnianie dwuskładnikowe',
            'mfa_prompt'     => 'Wpisz 6-cyfrowy kod weryfikacyjny z aplikacji uwierzytelniającej lub wprowadź kod odzyskiwania.',
            'mfa_code_label' => 'Kod weryfikacyjny lub kod odzyskiwania',
            'mfa_submit'     => 'Weryfikuj',
            'mfa_cancel'     => 'Powrót do logowania',
            'mfa_error_invalid' => 'Nieprawidłowy kod weryfikacyjny lub odzyskiwania.',
        ],

        'dashboard' => [
            'page_title'           => 'Panel sterowania - Pure Blog',
            'stat_published'       => 'Opublikowanych postów',
            'stat_total_words'     => 'Słowa',
            'stat_books'           => 'Starczyłoby na tyle książek',
            'stat_avg_words'       => 'Średnia słów na post',
            'stat_this_year'       => 'Posty',
            'stat_top_tags'        => 'Główne tagi',
            'stat_no_tags'         => 'Brak tagów',
            'section_this_year'    => 'Ostatnia aktywność (ostatnie 12 miesięcy)',
            'section_all_time'     => 'Statystyki wszystkich postów',
            'stat_words_this_year' => 'Słowa',
            'chart_title'          => 'Posty w miesiącu (ostatnie 12 miesięcy)',
            'chart_all_years'      => 'Posty według roku',
            'chart_all_months'     => 'Posty według miesiąca',
            'chart_all_days'       => 'Posty według dnia',
            'all_tags'             => 'Wszystkie tagi',
            'write_post'           => 'Nowy post',
            'last_published'       => 'Ostatni post opublikowano',
            'no_posts_yet'         => 'Brak postów',
            'version_label'        => 'Wersja Pure Blog',
            'version_uptodate'     => 'Aktualna, dobra robota 👍🏻',
            'version_update_link'  => 'dostępna wersja {latest} - uruchom aktualizację!',
            'time_just_now'        => 'Chwilę temu',
            'time_minute_ago'      => '{n} minutę temu',
            'time_minutes_ago'     => '{n} minut temu',
            'time_hour_ago'        => '{n} godzinę temu',
            'time_hours_ago'       => '{n} godzin temu',
            'time_day_ago'         => '{n} dzień temu',
            'time_days_ago'        => '{n} dni temu',
        ],

        'content' => [
            'page_title'            => 'Zawartość - Pure Blog',
            'tabs_label'            => 'Zawartość',
            'tab_posts'             => 'Posty',
            'tab_pages'             => 'Strony',
            'view_post'             => 'Zobacz wpis',
            'view_page'             => 'Zobacz stronę',
            'new_post'              => 'Nowy Post',
            'choose_layout'         => 'Wybierz układ',
            'default_post'          => 'Domyślny post',
            'cancel'                => 'Anuluj',
            'search_label'          => 'Szukaj postów',
            'search_placeholder'    => 'Szukaj postu...',
            'no_posts_found'        => 'Nie znaleziono postów dla "{search}".',
            'no_posts_filtered'     => 'Nie znaleziono postów dla {label}.',
            'no_posts'              => 'Brak postów, bierz się do pisania!',
            'filter_title'          => 'Szukanie i filtrowanie',
            'filter_year'           => 'Rok',
            'filter_month'          => 'Miesiąc',
            'filter_tag'            => 'Tag',
            'filter_all_years'      => 'Wszystkie lata',
            'filter_all_months'     => 'Wszystkie miesiące',
            'filter_all_tags'       => 'Wszystkie tagi',
            'filter_status'         => 'Status',
            'filter_all_statuses'   => 'Wszystkie',
            'filter_layout'         => 'Layout', // ENGLISH PLACEHOLDER
            'filter_all_layouts'    => 'All layouts', // ENGLISH PLACEHOLDER
            'filter_apply'          => 'Zatwierdź',
            'filter_active'         => 'Filtrowanie po: {label}.',
            'filter_clear'          => 'Wyczyść',
            'filter_recent'         => 'ostatnie 12 miesięcy',
            'notice_post_saved'     => 'Post zapisany.',
            'notice_post_deleted'   => 'Post usunięty.',
            'notice_page_saved'     => 'Strona zapisana.',
            'notice_page_deleted'   => 'Strona usunięta.',
            'pagination_newer'      => '⬅ Nowsze posty',
            'pagination_older'      => 'Starsze posty ➡',
            'new_page'              => 'Nowa strona',
            'no_pages'              => 'Brak stron.',
            'badge_home'                 => 'Strona główna',
            'badge_blog'                 => 'Strona bloga',
            'badge_search'               => 'Strona wyszukiwania',
            'badge_special_page_label'   => 'Strona specjalna',
            'badge_docs_link'            => 'dokumentacji',
            'badge_home_popover'         => 'To jest strona specjalna skonfigurowana jako strona główna. Przeczytaj więcej w {docs_link}.',
            'badge_blog_popover'         => 'To jest strona specjalna skonfigurowana do wyświetlania wpisów na blogu. Przeczytaj więcej w {docs_link}.',
            'badge_search_popover'       => 'To jest strona specjalna skonfigurowana do obsługi wyszukiwarki. Przeczytaj więcej w {docs_link}.',
        ],

        'editor' => [
            'title_label'           => 'Tytuł',
            'content_label'         => 'Treść',
            'slug_label'            => 'Slug (opcjonalnie)',
            'description_label'     => 'Opis',
            'date_label'            => 'Data',
            'status_label'          => 'Status',
            'status_draft'          => 'Szkic',
            'status_scheduled'      => 'Scheduled', // ENGLISH PLACEHOLDER
            'status_published'      => 'Opublikowany',
            'images_title'          => 'Obrazy',
            'no_images'             => 'Brak obrazów.',
            'attached_images'       => 'Załączone obrazy:',
            'upload_label'          => 'Prześlij obraz',
            'media_title'           => 'Media', // ENGLISH PLACEHOLDER
            'no_media'              => 'No media yet.', // ENGLISH PLACEHOLDER
            'attached_media'        => 'Attached media:', // ENGLISH PLACEHOLDER
            'upload_media_label'    => 'Upload images or videos', // ENGLISH PLACEHOLDER
            'notice_media_uploaded' => 'Media uploaded and inserted.', // ENGLISH PLACEHOLDER
            'media_description_saved' => 'Video description saved.', // ENGLISH PLACEHOLDER
            'video_description'     => 'Video description', // ENGLISH PLACEHOLDER
            'video_description_placeholder' => 'Describe the video for screen readers', // ENGLISH PLACEHOLDER
            'upload'                => 'Prześlij',
            'copy'                  => 'Kopiuj',
            'delete'                => 'Usuń',
            'notice_saved_post'     => 'Post zapisany.',
            'notice_saved_page'     => 'Strona zapisana.',
            'notice_image_uploaded' => 'Obraz przesłany. Możesz użyć poniższego kodu markdown.',
            'error_not_found_post'  => 'Post nie znaleziony.',
            'error_not_found_page'  => 'Strona nieznaleziona.',
            'error_title_required'  => 'Tytuł jest obowiązkowy.',
            'error_invalid_status'  => 'Dostępny Status to szkic lub opublikowany.',
            'error_save_post'       => 'Nie można zapisać postu.',
            'error_save_page'       => 'Nie można zapisać strony.',
            'error_empty_slug'      => 'Tytuł musi zawierać przynajmniej jedną literę. Dzięki temu slug będzie mógł być wygenerowany.',
            'tip_markdown'               => 'Markdown',
            'preview_title'              => 'Podgląd',
            'error_upload_no_slug'       => 'Zapisz post, aby wygenerować mu slug.',
            'error_upload_no_file'       => 'Brak przesłanego obrazu.',
            'error_upload_failed'        => 'Przesyłanie nieudane.',
            'error_upload_type'          => 'Niewspierany typ obrazu. Użyj JPG, PNG, GIF, WebP, lub AVIF.',
            'error_upload_invalid_slug'  => 'Nieprawidłowy slug dla katalogu obrazów.',
            'error_image_folder_missing' => 'Nie udało się odnaleźć katalogu obrazów.',
            'error_upload_folder_create' => 'Nie udało się utworzyć katalogu obrazów.',
            'error_image_invalid_path'   => 'Nieprawidłowa ścieżka do obrazu.',
            'error_upload_invalid_name'  => 'Nieprawidłowa nazwa pliku.',
            'error_upload_duplicate'     => 'Zdublowana nazwa, zmień nazwę obrazu (bezpiecznie zapisany jako "{filename}").',
            'error_upload_save'          => 'Zapis wgranych obrazów nieudany.',
            'error_delete_missing_data'  => 'Brak danych obrazu.',
            'error_image_not_found'      => 'Obraz nie istnieje.',
            'error_delete_failed'        => 'Usuwanie obrazu nieudane.',
            'error_autosave_method'      => 'Niedozwolona metoda',
            'error_autosave_csrf'        => 'Nieprawidłowy token CSRF',
            'error_autosave_slug'        => 'Slug jest wymagany',
            'error_autosave_write'       => 'Auto zapis nieudany',
            'js_autosaving'              => 'Auto zapis\u2026',
            'js_autosaved'               => 'Auto zapis zakończony',
            'js_autosave_failed'         => 'Auto zapis nieudany',
            'js_autosave_banner'         => 'Istnieje auto zapis zmian z {time}.',
            'js_view'                    => 'Pokaż',
            'js_hide'                    => 'Ukryj',
            'js_restore'                 => 'Odtwórz',
            'js_discard'                 => 'Odrzuć',
            'js_title_label'             => 'Tytuł:',
            'js_save_failed'             => 'Zapis nieudany',
            'js_save_before_upload'      => 'Nie można zapisać przed wgraniem obrazu.',
            'js_copied'                  => 'Skopiowano',
            'js_copy_failed'             => 'Nie udało się skopiować do schowka. Skopiuj ręcznie.',
            'js_save_post_first'         => 'Zapisz post, aby wygenerować slug i datę.',
            'js_save_page_first'         => 'Zapisz stronę, aby wygenerować slug.',
            'js_upload_failed'           => 'Przesyłanie obrazu nieudane.',
            'feature_image'              => 'Feature image', // ENGLISH PLACEHOLDER
            'js_feature_image_confirm'   => 'Replace the current feature image with "{filename}"?', // ENGLISH PLACEHOLDER
            'js_feature_image_failed'    => 'Failed to update feature image.', // ENGLISH PLACEHOLDER
            'insert_gallery'             => 'Wstaw galerię',
            'insert_gallery_title'       => 'Wstaw załączone obrazy jako galerię do edytora',
            'js_gallery_inserted'        => 'Galeria wstawiona',
            'gallery_picker_title'       => 'Wybierz obrazy do galerii',
            'gallery_picker_select_all'  => 'Zaznacz wszystko',
            'gallery_picker_deselect_all'=> 'Odznacz wszystko',
            'gallery_picker_insert'      => 'Wstaw galerię ({count})',
            'gallery_picker_cancel'      => 'Anuluj',
            'gallery_picker_count'       => 'Wybrano {selected} z {total}',
            'gallery_picker_no_selected' => 'Wybierz co najmniej jeden obraz.',
        ],

        'images' => [
            'page_title'         => 'Images - Pure Blog Admin', // ENGLISH PLACEHOLDER
            'heading'            => 'Images', // ENGLISH PLACEHOLDER
            'no_images'          => 'No images uploaded yet.', // ENGLISH PLACEHOLDER
            'search_label'       => 'Search images', // ENGLISH PLACEHOLDER
            'search_placeholder' => 'Search by filename…', // ENGLISH PLACEHOLDER
            'clear_search'       => 'Clear search', // ENGLISH PLACEHOLDER
            'deleted'            => 'Image deleted.', // ENGLISH PLACEHOLDER
            'delete_confirm'     => 'Delete this image?', // ENGLISH PLACEHOLDER
            'delete_used_warning'=> 'This image is being used in the content below. You should remove it to avoid broken links.', // ENGLISH PLACEHOLDER
            'delete_anyway'      => 'Delete anyway', // ENGLISH PLACEHOLDER
            'cancel'             => 'Cancel', // ENGLISH PLACEHOLDER
            'used_in_label'      => 'Użyto w:',
            'used_in_count'      => '{count} elementów',
            'type_post'          => 'Wpis',
            'type_page'          => 'Strona',
            'error_delete'       => 'Unable to delete image.', // ENGLISH PLACEHOLDER
            'pagination_prev'    => '⬅ Previous', // ENGLISH PLACEHOLDER
            'pagination_next'    => 'Next ➡', // ENGLISH PLACEHOLDER
        ],

        'notices' => [
        ],

        'post_editor' => [
            'page_title'          => 'Edytor postu',
            'settings_title'      => 'Ustawienia postu',
            'tags_label'          => 'Tagi',
            'save'                => 'Zapisz',
            'preview'             => 'Podgląd',
            'delete'              => 'Usuń',
            'delete_confirm'      => 'Usunąć ten post?',
            'delete_image_confirm'=> 'Usunąć ten obraz?',
            'edit_title'          => 'Edycja postu - Pure Blog',
            'new_title'           => 'Nowy post - Pure Blog',
        ],

        'page_editor' => [
            'page_title'          => 'Edytor strony',
            'settings_title'      => 'Ustawienia strony',
            'nav_label'           => 'Wyświetlić w menu?',
            'nav_yes'             => 'Tak',
            'nav_no'              => 'Nie',
            'save'                => 'Zapisz',
            'preview'             => 'Podgląd',
            'delete'              => 'Usuń',
            'delete_confirm'      => 'Usunąć tę stronę?',
            'delete_image_confirm'=> 'Usunąć ten obraz?',
            'edit_title'          => 'Edytuj stronę - Pure Blog',
            'new_title'           => 'Nowa strona - Pure Blog',
        ],

        'delete_post' => [
            'page_title'     => 'Usuń Post - Pure Blog',
            'heading'        => 'Usuń Post',
            'already_deleted'=> 'Ten post został już usunięty (lub już nie istnieje).',
            'confirm'        => 'Czy na pewno usunąć "{title}"?',
            'submit'         => 'Tak, usuń ten post',
            'error_missing'  => 'Brak slug dla postu.',
            'error_not_found'=> 'Nie znaleziono postu.',
            'error_delete'   => 'Usuwanie postu nieudane.',
        ],

        'delete_page' => [
            'page_title'     => 'Usuń Stronę - Pure Blog',
            'heading'        => 'Usuń Stronę',
            'already_deleted'=> 'Ta strona została już usunięta (lub już nie istnieje).',
            'confirm'        => 'Czy na pewno usunąć "{title}"?',
            'submit'         => 'Tak, usuń tę stronę',
            'error_missing'  => 'Brak slug dla stron.',
            'error_not_found'=> 'Strona nie istnieje.',
            'error_delete'   => 'Usuwanie strony nieudane.',
        ],

        'pages' => [
            'page_title'     => 'Strony - Pure Blog',
            'heading'        => 'Strony',
            'new_page'       => 'Nowa strona',
            'no_pages'       => 'Nic tu jeszcze nie ma.',
            'notice_saved'   => 'Strona zapisana.',
            'notice_deleted' => 'Strona usunięta.',
        ],

        'footer' => [
            'created_by' => 'utworzony z 💙 przez',
            'docs'       => 'Dokumentacja',
            'mastodon'   => 'Mastodon',
            'source'     => 'Kod źródłowy',
        ],

        'settings' => [

            'nav' => [
                'site'    => 'Witryna',
                'theme'   => 'Design',
                'css'     => 'CSS',
                'user'    => 'Użytkownik',
                'updates' => 'Aktualizacje',
                'save'    => 'Zapisz Ustawienia',
            ],

            'site' => [
                'page_title'         => 'Ustawienia Strony - Pure Blog',
                'heading'            => 'Ustawienia strony',
                'section_title'      => 'Ustawienia Strony',
                'site_title'         => 'Tytuł',
                'tagline'            => 'Slogan (opcjonalnie)',
                'description'        => 'Opis',
                'email'              => 'Email (opcjonalnie)',
                'posts_per_page'            => 'Ilość postów na stronę',
                'search_excerpt_length'     => 'Rozmiar indeksowania treści',
                'search_excerpt_length_tip' => 'Ilość znaków indeksowania każdego postu dla wyszukiwarki. Wyższe wartości dodatnio wpływają na dokładność wyszukiwania, jednak zwiększają zużycie pamięci. Ustaw na 0, aby indeksować pełne treści postów.',
                'search_include_pages'      => 'Uwzględniaj strony w wynikach wyszukiwania',
                'language'           => 'Język',
                'timezone'           => 'Strefa czasowa',
                'date_format'        => 'Format daty',
                'show_reading_time'  => 'Show reading time on posts', // ENGLISH PLACEHOLDER
                'homepage'           => 'Strona główna',
                'blog_page'          => 'Blog',
                'base_url'           => 'Adres URL',
                'favicon'            => 'Favicon',
                'apple_touch_icon'   => 'Apple touch icon', // ENGLISH PLACEHOLDER
                'og_image'           => 'Obraz grafiki podglądu (Open Graph)',
                'og_image_format'    => 'Format obrazu grafiki podglądu (Open Graph)',
                'og_banner'          => 'Baner (domyślnie)',
                'og_square'          => 'Kwadrat',
                'sidebar_logo'       => 'Sidebar logo', // ENGLISH PLACEHOLDER
                'custom_nav'         => 'Własne elementy nawigacji',
                'custom_nav_only'    => 'Zastąp całą nawigację własnymi elementami',
                'custom_routes'      => 'Niestandardowe trasy',
                'homepage_default'   => 'Posty na blogu (domyślnie)',
                'blog_use_homepage'  => 'Używaj strony głównej',
                'blog_hidden'        => 'Ukryta (wyłącza stronę bloga)',
                'admin_ui_section'   => 'Interfejs Admina',
                'admin_homepage'     => 'Strona domowa Admina',
                'admin_homepage_dashboard'  => 'Panel sterowania',
                'admin_homepage_content'    => 'Zawartość',
                'admin_hide_dashboard'      => 'Ukryj panel sterowania',
                'enable_blog_posts'         => 'Włącz funkcje bloga (posty, tagi, statystyki)',
                'cache_section'      => 'Cache',
                'cache_enable'       => 'Włącz cache strony',
                'rss_ttl'            => 'Czas przechowywania cache RSS',
                'header_injects'          => 'Dodatkowe nagłówki HTML',
                'head_inject_page_label'  => 'HTML nagłówka strony',
                'head_inject_post_label'  => 'HTML nagłówka postu',
                'footer_injects'          => 'Kod stopki',
                'footer_inject_page_label'=> 'HTML stopki strony',
                'footer_inject_post_label'=> 'HTML stopki postu',
                'notice_updated'     => 'Ustawienia zaktualizowane.',
                'error_save'         => 'Zapis ustawień nieudany.',
                'error_title'        => 'Tytuł jest wymagany.',
                'error_posts_per_page'=> 'Ilość postów na stronie musi mieścić się pomiędzy 1 a 100.',
                'error_timezone'     => 'Strefa czasowa musi być poprawnym identyfikatorem strefy czasowej w PHP (np. UTC, Europe/London).',
                'error_date_format'  => 'Format daty jest wymagany.',
                'error_homepage'     => 'Strona główna musi odnosić się do istniejącej strony.',
                'error_blog_page'    => 'Blog musi odnosić się do istniejącej strony.',
                'search_page'        => 'Strona wyszukiwania',
                'search_page_none'   => 'Brak (wyłączone)',
                'error_search_page'       => 'Strona wyszukiwania musi być istniejącą stroną.',
                'notice_search_missing'   => 'Domyślna strona wyszukiwania ("{slug}") jeszcze nie istnieje.',
                'notice_search_created'   => 'Domyślna strona wyszukiwania utworzona.',
                'create_search_page'      => 'Utwórz domyślną stronę wyszukiwania',
                'dismiss_search_notice'   => 'Zamknij',
                'error_og_format'        => 'Obraz grafiki podglądu (Open Graph) musi mieć postać kształt bannera lub kwadratu.',
                'error_apple_touch_icon_type' => 'Apple touch icon must be a PNG image.', // ENGLISH PLACEHOLDER
                'current'                => 'Bieżąca',
                'tip_language_link'      => 'kod języka',
                'tip_timezone_link'      => 'lista stref czasowych PHP',
                'tip_date_format_link'   => 'dokumentacja formatu daty PHP',
                'tip_favicon'            => 'kwadrat o boku 512px sprawdza się najlepiej',
                'tip_apple_touch_icon'   => 'Must be square and PNG format, recommended 360x360px', // ENGLISH PLACEHOLDER
                'tip_sidebar_logo'       => '124x34px works best', // ENGLISH PLACEHOLDER
                'tip_og_image'           => 'zalecane 1360x712px na baner, lub 1200x1200px na kwadrat',
                'tip_og_image_dynamic'   => 'Pozostaw puste, aby używać dynamicznie generowanych banerów Open Graph.',
                'tip_og_image_doc_link'  => 'Przeczytaj dokumentację',
                'tip_one_per_line'       => 'jeden na wiersz',
                'tip_optional'           => 'opcjonalnie',
                'tip_rss_ttl'            => 'sekundy, ustaw 0, aby wyłączyć',
            ],

            'user' => [
                'page_title'           => 'Ustawienia Użytkownika - Pure Blog',
                'heading'              => 'Ustawienia użytkownika',
                'section_account'      => 'Konto',
                'username'             => 'Użytkownik Admin',
                'section_password'     => 'Zmiana hasła',
                'current_password'     => 'Bieżące hasło',
                'new_password'         => 'Nowe hasło',
                'confirm_password'     => 'Potwierdź nowe hasło',
                'notice_updated'       => 'Ustawienia zaktualizowane.',
                'error_save'           => 'Nie udało się zapisać ustawień.',
                'error_username'       => 'Użytkownik Admin jest wymagany.',
                'error_password_match' => 'Pola Nowe hasło i Potwierdź nowe hasło nie są identyczne.',
                'error_password_wrong' => 'Bieżące hasło jest nieprawidłowe.',
                'mfa_section_title'             => 'Uwierzytelnianie dwuskładnikowe',
                'mfa_status_enabled'            => 'Aktywne',
                'mfa_enabled_desc'              => 'Uwierzytelnianie dwuskładnikowe jest aktywne na Twoim koncie.',
                'mfa_disabled_desc'             => 'Dodaj dodatkową warstwę zabezpieczeń do swojego konta, wymagając 6-cyfrowego kodu z aplikacji uwierzytelniającej podczas logowania.',
                'mfa_setup_btn'                 => 'Skonfiguruj uwierzytelnianie dwuskładnikowe',
                'mfa_setup_instructions'        => 'Zeskanuj kod QR za pomocą aplikacji uwierzytelniającej (np. 1Password, Google Authenticator, Bitwarden lub Aegis), a następnie wpisz poniżej 6-cyfrowy kod weryfikacyjny.',
                'mfa_manual_entry_intro'        => 'Lub wprowadź ten klucz tajny ręcznie do swojej aplikacji:',
                'mfa_enter_code_label'          => 'Kod weryfikacyjny',
                'mfa_activate_btn'              => 'Weryfikuj i aktywuj',
                'mfa_cancel_btn'                => 'Anuluj konfigurację',
                'mfa_notice_enabled'            => 'Uwierzytelnianie dwuskładnikowe zostało pomyślnie włączone.',
                'mfa_notice_disabled'           => 'Uwierzytelnianie dwuskładnikowe zostało wyłączone.',
                'mfa_notice_codes_regenerated'  => 'Wygenerowano nowe kody odzyskiwania.',
                'mfa_error_verify_code'         => 'Nieprawidłowy kod weryfikacyjny. Sprawdź aplikację uwierzytelniającą i spróbuj ponownie.',
                'mfa_error_password'            => 'Nieprawidłowe hasło.',
                'mfa_backup_codes_heading'      => 'Ważne: Zapisz swoje kody odzyskiwania',
                'mfa_backup_codes_warning'      => 'Przechowuj te kody w bezpiecznym miejscu. W przypadku utraty dostępu do aplikacji uwierzytelniającej, każdy kod może zostać użyty jednorazowo do zalogowania się.',
                'mfa_regenerate_codes_title'    => 'Generuj nowe kody odzyskiwania',
                'mfa_regenerate_codes_btn'      => 'Generuj nowe kody',
                'mfa_disable_title'             => 'Wyłącz uwierzytelnianie dwuskładnikowe',
                'mfa_disable_btn'               => 'Wyłącz 2FA',
                'mfa_confirm_password_label'    => 'Potwierdź swoje bieżące hasło',
                'mfa_copy'                      => 'Kopiuj',
                'mfa_copy_codes'                => 'Kopiuj wszystkie kody',
                'mfa_copied'                    => 'Skopiowano!',
            ],

            'theme' => [
                'page_title'          => 'Ustawienia Motywu - Pure Blog',
                'heading'             => 'Motyw i układ',
                'notice_updated'      => 'Ustawienia zapisane.',
                'notice_theme_applied'=> 'Theme applied.', // ENGLISH PLACEHOLDER
                'notice_theme_created'=> 'Theme created and saved to your collection.', // ENGLISH PLACEHOLDER
                'notice_theme_imported'=> 'Theme imported successfully.', // ENGLISH PLACEHOLDER
                'notice_theme_deleted'=> 'Theme deleted.', // ENGLISH PLACEHOLDER
                'notice_preview_exited'=> 'Theme preview exited.', // ENGLISH PLACEHOLDER
                'error_save'          => 'Nie udało się zapisać ustawień.',
                'error_font'          => 'Styl czcionki może przyjmować wartości sans, serif lub mono.',
                'error_admin_font'    => 'Czcionka panelu admina może przyjmować wartości sans, serif lub mono.',
                'error_admin_color'   => 'Tryb koloru panelu admina może przyjmować wartości light, dark lub auto.',
                'error_color_mode'    => 'Tryb koloru może przyjmować wartości light, dark lub auto.',
                'error_post_layout'   => 'Układ listy postów może przyjmować wartości excerpt, full lub archive.',
                'error_theme_name'    => 'Please enter a valid theme name.', // ENGLISH PLACEHOLDER
                'error_theme_folder'  => 'Unable to create themes directory.', // ENGLISH PLACEHOLDER
                'error_theme_save'    => 'Unable to save theme file.', // ENGLISH PLACEHOLDER
                'error_theme_upload'  => 'Failed to upload theme file.', // ENGLISH PLACEHOLDER
                'error_theme_invalid' => 'Invalid JSON theme file format.', // ENGLISH PLACEHOLDER
                'error_theme_not_found'=> 'Theme not found.', // ENGLISH PLACEHOLDER
                'error_theme_delete'  => 'Unable to delete theme.', // ENGLISH PLACEHOLDER
                'error_theme_empty'   => 'Please provide colour values for light mode, dark mode, or both.', // ENGLISH PLACEHOLDER
                'error_theme_light_incomplete' => 'Please fill in all colour fields for light mode, or leave them all blank.', // ENGLISH PLACEHOLDER
                'error_theme_dark_incomplete'  => 'Please fill in all colour fields for dark mode, or leave them all blank.', // ENGLISH PLACEHOLDER
                'section_themes'      => 'Themes', // ENGLISH PLACEHOLDER
                'themes_intro'        => 'Choose a theme from your collection, create your own, or {link} to learn more.', // ENGLISH PLACEHOLDER
                'docs_link'           => 'read the documentation', // ENGLISH PLACEHOLDER
                'active'              => 'Active', // ENGLISH PLACEHOLDER
                'custom'              => 'Custom', // ENGLISH PLACEHOLDER
                'apply'               => 'Apply', // ENGLISH PLACEHOLDER
                'preview'             => 'Preview', // ENGLISH PLACEHOLDER
                'previewing'          => 'Previewing Theme', // ENGLISH PLACEHOLDER
                'preview_admin_only'  => 'Visible only to you', // ENGLISH PLACEHOLDER
                'exit_preview'        => 'Exit Preview', // ENGLISH PLACEHOLDER
                'delete_confirm'      => 'Are you sure you want to delete this custom theme?', // ENGLISH PLACEHOLDER
                'section_creator'     => 'Theme Creator', // ENGLISH PLACEHOLDER
                'creator_title'       => 'Theme Creator', // ENGLISH PLACEHOLDER
                'creator_tip'         => 'Create a custom theme by providing a name and hex colour codes.', // ENGLISH PLACEHOLDER
                'theme_name'          => 'Theme name', // ENGLISH PLACEHOLDER
                'save_theme'          => 'Save theme', // ENGLISH PLACEHOLDER
                'section_import'      => 'Import Theme', // ENGLISH PLACEHOLDER
                'import_file_label'   => 'Import theme file', // ENGLISH PLACEHOLDER
                'import_file_tip'     => '.json theme files', // ENGLISH PLACEHOLDER
                'import_button'       => 'Import theme', // ENGLISH PLACEHOLDER
                'section_fonts'       => 'Ustawienia czcionki',
                'site_font'           => 'Czcionka Strony',
                'admin_font'          => 'Czcionka panelu Admina',
                'section_color_mode'  => 'Tryb kolorów',
                'site_color_mode'     => 'Tryb kolorów Strony',
                'admin_color_mode'    => 'Tryb kolorów panelu Admina',
                'color_light'         => 'Jasny',
                'color_dark'          => 'Ciemny',
                'color_auto'          => 'Automatycznie',
                'light_mode'          => 'Tryb jasny',
                'dark_mode'           => 'Tryb ciemny',
                'color_background'    => 'Kolor tła',
                'color_text'          => 'Kolor tekstu',
                'color_accent'        => 'Kolor akcentu',
                'color_border'        => 'Kolor ramek',
                'color_accent_bg'     => 'Akcent tła',
                'section_post_layout' => 'Układ strony z postami',
                'layout_excerpt'      => 'Fragment postu',
                'layout_full'         => 'Pełna treść postu',
                'layout_archive'      => 'Data i tytuł',
            ],

            'css' => [
                'page_title'        => 'Własny styl CSS - Pure Blog',
                'heading'           => 'Ustawienia własnego stylu CSS',
                'notice_saved'      => 'Styl CSS zapisany.',
                'error_save_front'  => 'Nie udało się zapisać stylu CSS dla Strony.',
                'error_save_admin'  => 'Nie udało się zapisać stylu CSS dla panelu Admina.',
                'error_dir_front'   => 'Nie udało się utworzyć katalogu dla CSS Strony.',
                'error_dir_admin'   => 'Nie udało się utworzyć katalogu dla CSS panelu Admina.',
                'section_front'     => 'Styl CSS strony',
                'section_admin'     => 'Styl CSS panelu Admina',
                'placeholder_front' => 'Dodaj własny kod CSS do strony...',
                'placeholder_admin' => 'Dodaj własny kod CSS do Pure Blog CMS...',
            ],



        ],

    ],


];
