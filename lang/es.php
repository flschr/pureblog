<?php

// New additions to this language file may have English placeholder values.
// These are marked with "ENGLISH PLACEHOLDER" and should be reviewed by a native speaker.

return [

    'name' => 'Español',

    // -------------------------------------------------------------------------
    // Date localisation
    // Full names must match what PHP's date('F') and date('l') produce.
    // Short names must match date('M') and date('D').
    // -------------------------------------------------------------------------
    'date' => [
        'months' => [
            'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
        ],
        'months_short' => [
            'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun',
            'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic',
        ],
        'days' => [
            'Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado',
        ],
        'days_short' => [
            'Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb',
        ],
    ],

    // -------------------------------------------------------------------------
    // Frontend (public-facing)
    // -------------------------------------------------------------------------
    'frontend' => [
        'nav_home'              => 'Inicio',
        'site_title_fallback'   => 'Página',
        'pagination_newer'      => '⬅ Entradas más recientes',
        'pagination_older'      => 'Entradas más antiguas ➡',
        'no_posts'              => 'Aún no hay entradas, ¡ponte a escribir! 🙃',
        'prev_post'             => '⬅ Entrada anterior',
        'next_post'             => 'Entrada siguiente ➡',
        'post_not_found'        => 'Entrada no encontrada',
        'post_not_found_detail' => 'La entrada que solicitaste no pudo encontrarse.',
        'search_label'          => 'Buscar entradas',
        'search_placeholder'    => 'Buscar una entrada...',
        'search_button'         => 'Buscar',
        'search_empty'          => 'Introduce un término de búsqueda para encontrar entradas.',
        'no_posts_found'        => 'No se encontraron entradas para "{search}".',
        'search_result'         => '{n} resultado encontrado.',
        'search_results'        => '{n} resultados encontrados.',
        'page_not_found'        => 'Página no encontrada',
        'page_not_found_detail' => 'La página que solicitaste no se pudo encontrar.',
        'no_tag_selected'       => 'Ninguna etiqueta seleccionada.',
        'no_posts_for_tag'      => 'No se encontraron entradas para esta etiqueta.',
        'edit_post'             => 'Edit post', // ENGLISH PLACEHOLDER
        'edit_page'             => 'Edit page', // ENGLISH PLACEHOLDER
        'reading_time'          => '{n} min read', // ENGLISH PLACEHOLDER
    ],

    // -------------------------------------------------------------------------
    // Admin
    // -------------------------------------------------------------------------
    'admin' => [

        'nav' => [
            'dashboard'     => 'Panel de control',
            'content'       => 'Páginas',
            'settings'      => 'Ajustes',
            'view_site'     => 'Ver sitio',
            'clear_cache'   => 'Limpiar caché',
            'log_out'       => 'Cerrar sesión',
            'cache_cleared' => 'Caché limpiada.',
            'invalid_action'=> 'Acción de administrador no válida.',
            'images'        => 'Images', // ENGLISH PLACEHOLDER
        ],

        'login' => [
            'page_title'     => 'Inicio de sesión - Pure Blog',
            'heading'        => 'Inicio de sesión',
            'username'       => 'Usuario',
            'password'       => 'Contraseña',
            'remember_me'    => 'Recuérdame',
            'submit'         => 'Iniciar sesión',
            'error_invalid'  => 'Credenciales no válidas.',
            'error_lockout'  => 'Demasiados intentos fallidos. Inténtalo de nuevo en {minutes} minuto(s).',
            'error_lockout_5'=> 'Demasiados intentos fallidos. Inténtalo de nuevo en 5 minutos.',
            'mfa_page_title' => 'Autenticación de dos factores - Pure Blog',
            'mfa_heading'    => 'Autenticación de dos factores',
            'mfa_prompt'     => 'Introduce el código de verificación de 6 dígitos de tu aplicación de autenticación o introduce un código de recuperación.',
            'mfa_code_label' => 'Código de verificación o de recuperación',
            'mfa_submit'     => 'Verificar',
            'mfa_cancel'     => 'Volver al inicio de sesión',
            'mfa_error_invalid' => 'Código de verificación o de recuperación no válido.',
        ],

        'dashboard' => [
            'page_title'           => 'Dashboard - Pure Blog',
            'stat_published'       => 'Entradas publicadas',
            'stat_total_words'     => 'Total de palabras',
            'stat_books'           => 'Libros equivalentes',
            'stat_avg_words'       => 'Palabras promedio por entrada',
            'section_this_year'    => 'Actividad reciente (últimos 12 meses)',
            'section_all_time'     => 'Estadísticas de entradas (siempre)',
            'stat_words_this_year' => 'Palabras escritas',
            'stat_this_year'       => 'Entradas',
            'stat_top_tags'        => 'Etiquetas principales',
            'stat_no_tags'         => 'Sin etiquetas todavía',
            'chart_title'          => 'Entradas por mes (últimos 12 meses)',
            'chart_all_years'      => 'Entradas por año',
            'chart_all_months'     => 'Entradas por mes',
            'chart_all_days'       => 'Entradas por día de la semana',
            'all_tags'             => 'Todas las etiquetas',
            'write_post'           => 'Escribir una entrada',
            'last_published'       => 'Última entrada publicada',
            'no_posts_yet'         => 'Aún no hay entradas',
            'version_label'        => 'Versión de Pure Blog',
            'version_uptodate'     => 'Actualizado, ¡buen trabajo! 👍🏻',
            'version_update_link'  => '{latest} disponible - ¡actualizar ahora!',
            'time_just_now'        => 'Ahora mismo',
            'time_minute_ago'      => 'Hace {n} minuto',
            'time_minutes_ago'     => 'Hace {n} minutos',
            'time_hour_ago'        => 'Hace {n} hora',
            'time_hours_ago'       => 'Hace {n} horas',
            'time_day_ago'         => 'Hace {n} día',
            'time_days_ago'        => 'Hace {n} días',
        ],

        'content' => [
            'page_title'            => 'Content - Pure Blog',
            'tabs_label'            => 'Content tabs',
            'tab_posts'             => 'Posts',
            'tab_pages'             => 'Pages',
            'view_post'             => 'Ver entrada',
            'view_page'             => 'Ver página',
            'new_post'              => 'New post',
            'choose_layout'         => 'Choose a layout',
            'default_post'          => 'Default post',
            'cancel'                => 'Cancel',
            'search_label'          => 'Search posts',
            'search_placeholder'    => 'Search for a post...',
            'no_posts_found'        => 'No posts found for "{search}".',
            'no_posts_filtered'     => 'No se encontraron entradas para {label}.',
            'no_posts'              => 'No posts yet, get writing!',
            'filter_title'          => 'Buscar y filtrar',
            'filter_year'           => 'Año',
            'filter_month'          => 'Mes',
            'filter_tag'            => 'Etiqueta',
            'filter_all_years'      => 'Todos los años',
            'filter_all_months'     => 'Todos los meses',
            'filter_all_tags'       => 'Todas las etiquetas',
            'filter_status'         => 'Estado',
            'filter_all_statuses'   => 'Todos',
            'filter_layout'         => 'Layout', // ENGLISH PLACEHOLDER
            'filter_all_layouts'    => 'All layouts', // ENGLISH PLACEHOLDER
            'filter_apply'          => 'Aplicar',
            'filter_active'         => 'Filtrado por: {label}.',
            'filter_clear'          => 'Borrar',
            'filter_recent'         => 'últimos 12 meses',
            'notice_post_saved'     => 'Post saved.',
            'notice_post_deleted'   => 'Post deleted.',
            'notice_page_saved'     => 'Page saved.',
            'notice_page_deleted'   => 'Page deleted.',
            'pagination_newer'      => '⬅ Newer posts',
            'pagination_older'      => 'Older posts ➡',
            'new_page'              => 'New page',
            'no_pages'              => 'No pages yet.',
            'badge_home'                 => 'Página de inicio',
            'badge_blog'                 => 'Página de blog',
            'badge_search'               => 'Página de búsqueda',
            'badge_special_page_label'   => 'Página especial',
            'badge_docs_link'            => 'la documentación',
            'badge_home_popover'         => 'Esta es una página especial configurada como página de inicio. Lea más en {docs_link}.',
            'badge_blog_popover'         => 'Esta es una página especial configurada para mostrar las entradas del blog. Lea más en {docs_link}.',
            'badge_search_popover'       => 'Esta es una página especial configurada para gestionar las búsquedas. Lea más en {docs_link}.',
        ],

        'editor' => [
            'title_label'           => 'Título',
            'content_label'         => 'Contenido',
            'slug_label'            => 'Slug (opcional)',
            'description_label'     => 'Descripción',
            'date_label'            => 'Fecha',
            'status_label'          => 'Estado',
            'status_draft'          => 'Borrador',
            'status_scheduled'      => 'Scheduled', // ENGLISH PLACEHOLDER
            'status_published'      => 'Publicado',
            'images_title'          => 'Imágenes',
            'no_images'             => 'Aún no hay imágenes.',
            'attached_images'       => 'Imágenes adjuntas:',
            'upload_label'          => 'Subir imagen',
            'media_title'           => 'Media', // ENGLISH PLACEHOLDER
            'no_media'              => 'No media yet.', // ENGLISH PLACEHOLDER
            'attached_media'        => 'Attached media:', // ENGLISH PLACEHOLDER
            'upload_media_label'    => 'Upload images or videos', // ENGLISH PLACEHOLDER
            'notice_media_uploaded' => 'Media uploaded and inserted.', // ENGLISH PLACEHOLDER
            'media_description_saved' => 'Video description saved.', // ENGLISH PLACEHOLDER
            'video_description'     => 'Video description', // ENGLISH PLACEHOLDER
            'video_description_placeholder' => 'Describe the video for screen readers', // ENGLISH PLACEHOLDER
            'upload'                => 'Subir',
            'copy'                  => 'Copiar',
            'delete'                => 'Eliminar',
            'notice_saved_post'     => 'Entrada guardada.',
            'notice_saved_page'     => 'Página guardada.',
            'notice_image_uploaded' => 'Imagen subida. Puedes copiar el markdown a continuación.',
            'error_not_found_post'  => 'Entrada no encontrada.',
            'error_not_found_page'  => 'Página no encontrada.',
            'error_title_required'  => 'El título es obligatorio.',
            'error_invalid_status'  => 'El estado debe ser borrador o publicado.',
            'error_save_post'       => 'No se pudo guardar la entrada.',
            'error_save_page'       => 'No se pudo guardar la página.',
            'tip_markdown'          => 'Markdown',
            'error_empty_slug'           => 'El título debe contener al menos una letra o número para generar un slug.',
            'preview_title'              => 'Vista previa',
            'error_upload_no_slug'       => 'Guarda la entrada primero para que tenga un slug.',
            'error_upload_no_file'       => 'No se subió ninguna imagen.',
            'error_upload_failed'        => 'Error al subir la imagen.',
            'error_upload_too_large'     => 'La imagen es demasiado grande. Tamaño máximo: 3 MB.',
            'error_upload_type'          => 'Tipo de imagen no compatible. Usa JPG, PNG, GIF, WebP o AVIF.',
            'error_upload_invalid_slug'  => 'Slug no válido para la carpeta de imágenes.',
            'error_image_folder_missing' => 'Carpeta de imágenes no encontrada.',
            'error_upload_folder_create' => 'No se pudo crear la carpeta de imágenes.',
            'error_image_invalid_path'   => 'Ruta de imagen no válida.',
            'error_upload_invalid_name'  => 'Nombre de archivo no válido.',
            'error_upload_duplicate'     => 'Nombre duplicado, por favor renombra la imagen (nombre saneado: «{filename}»).',
            'error_upload_save'          => 'No se pudo guardar el archivo subido.',
            'error_delete_missing_data'  => 'Faltan datos de la imagen.',
            'error_image_not_found'      => 'Imagen no encontrada.',
            'error_delete_failed'        => 'No se pudo eliminar la imagen.',
            'error_autosave_method'      => 'Método no permitido',
            'error_autosave_csrf'        => 'Token CSRF no válido',
            'error_autosave_slug'        => 'Se requiere un slug',
            'error_autosave_write'       => 'Error al guardar automáticamente',
            'js_autosaving'  => 'Guardando…',
            'js_autosaved'  => 'Guardado',
            'js_autosave_failed'  => 'Error al guardar automáticamente',
            'js_autosave_banner'  => 'Existen cambios guardados automáticamente desde {time}.',
            'js_view'  => 'Ver',
            'js_hide'  => 'Ocultar',
            'js_restore'  => 'Restaurar',
            'js_discard'  => 'Descartar',
            'js_title_label'  => 'Título:',
            'js_save_failed'  => 'Error al guardar',
            'js_save_before_upload'  => 'No se pudo guardar antes de subir la imagen.',
            'js_copied'  => 'Copiado',
            'js_copy_failed'  => 'No se pudo copiar al portapapeles. Por favor, copia manualmente.',
            'js_save_post_first'  => 'Guarda la entrada primero para que tenga un slug y una fecha.',
            'js_save_page_first'  => 'Guarda la página primero para que tenga un slug.',
            'js_upload_failed'           => 'Error al subir la imagen.',
            'feature_image'              => 'Feature image', // ENGLISH PLACEHOLDER
            'js_feature_image_confirm'   => 'Replace the current feature image with "{filename}"?', // ENGLISH PLACEHOLDER
            'js_feature_image_failed'    => 'Failed to update feature image.', // ENGLISH PLACEHOLDER
            'insert_gallery'             => 'Insertar galería',
            'insert_gallery_title'       => 'Insertar imágenes adjuntas como galería en el editor',
            'js_gallery_inserted'        => 'Galería insertada',
            'gallery_picker_title'       => 'Seleccionar imágenes para la galería',
            'gallery_picker_select_all'  => 'Seleccionar todo',
            'gallery_picker_deselect_all'=> 'Deseleccionar todo',
            'gallery_picker_insert'      => 'Insertar galería ({count})',
            'gallery_picker_cancel'      => 'Cancelar',
            'gallery_picker_count'       => '{selected} de {total} seleccionadas',
            'gallery_picker_no_selected' => 'Por favor, selecciona al menos una imagen.',
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
            'used_in_label'      => 'Usado en:',
            'used_in_count'      => '{count} elementos',
            'type_post'          => 'Entrada',
            'type_page'          => 'Página',
            'error_delete'       => 'Unable to delete image.', // ENGLISH PLACEHOLDER
            'pagination_prev'    => '⬅ Previous', // ENGLISH PLACEHOLDER
            'pagination_next'    => 'Next ➡', // ENGLISH PLACEHOLDER
        ],

        'notices' => [
        ],

        'post_editor' => [
            'page_title'          => 'Editor de entradas',
            'settings_title'      => 'Ajustes de la entrada',
            'tags_label'          => 'Etiquetas',
            'save'                => 'Guardar entrada',
            'preview'             => 'Vista previa',
            'delete'              => 'Eliminar entrada',
            'delete_confirm'      => '¿Eliminar esta entrada?',
            'delete_image_confirm'=> '¿Eliminar esta imagen?',
            'edit_title'          => 'Editar entrada - Pure Blog',
            'new_title'           => 'Nueva entrada - Pure Blog',
        ],

        'page_editor' => [
            'page_title'          => 'Editor de páginas',
            'settings_title'      => 'Ajustes de la página',
            'nav_label'           => '¿Incluir en el menú de navegación?',
            'nav_yes'             => 'Sí',
            'nav_no'              => 'No',
            'save'                => 'Guardar página',
            'preview'             => 'Vista previa',
            'delete'              => 'Eliminar página',
            'delete_confirm'      => '¿Eliminar esta página?',
            'delete_image_confirm'=> '¿Eliminar esta imagen?',
            'edit_title'          => 'Editar página - Pure Blog',
            'new_title'           => 'Nueva página - Pure Blog',
        ],

        'delete_post' => [
            'page_title'     => 'Eliminar entrada - Pure Blog',
            'heading'        => 'Eliminar entrada',
            'already_deleted'=> 'Esta entrada ya no existe.',
            'confirm'        => '¿Seguro que quieres eliminar "{title}"?',
            'submit'         => 'Sí, eliminar esta entrada',
            'error_missing'  => 'Falta el slug de la entrada.',
            'error_not_found'=> 'Entrada no encontrada.',
            'error_delete'   => 'No se pudo eliminar la entrada.',
        ],

        'delete_page' => [
            'page_title'     => 'Eliminar página - Pure Blog',
            'heading'        => 'Eliminar página',
            'already_deleted'=> 'Esta página ya no existe.',
            'confirm'        => '¿Seguro que quieres eliminar "{title}"?',
            'submit'         => 'Sí, eliminar esta página',
            'error_missing'  => 'Falta el slug de la página.',
            'error_not_found'=> 'Página no encontrada.',
            'error_delete'   => 'No se pudo eliminar la página.',
        ],

        'pages' => [
            'page_title'     => 'Páginas - Pure Blog',
            'heading'        => 'Páginas',
            'new_page'       => 'Nueva página',
            'no_pages'       => 'Aún no hay páginas.',
            'notice_saved'   => 'Página guardada.',
            'notice_deleted' => 'Página eliminada.',
        ],


        'footer' => [
            'created_by' => 'fue creado con 💙 por',
            'docs'       => 'Leer la documentación',
            'mastodon'   => 'Mastodon',
            'source'     => 'Código fuente',
        ],
        'settings' => [

            'nav' => [
                'site'    => 'Sitio',
                'theme'   => 'Design', // ENGLISH PLACEHOLDER
                'css'     => 'CSS',
                'user'    => 'Usuario',
                'updates' => 'Actualizaciones',
                'save'    => 'Guardar ajustes',
            ],

            'site' => [
                'page_title'          => 'Ajustes del sitio - Pure Blog',
                'heading'             => 'Ajustes del sitio',
                'section_title'       => 'Ajustes del sitio',
                'site_title'          => 'Título del sitio',
                'tagline'             => 'Lema del sitio (opcional)',
                'description'         => 'Descripción del sitio',
                'email'               => 'Correo del sitio (opcional)',
                'posts_per_page'            => 'Entradas por página',
                'search_excerpt_length'     => 'Longitud del extracto de búsqueda',
                'search_excerpt_length_tip' => 'Número de caracteres indexados por entrada para la búsqueda. Valores más grandes mejoran la precisión pero aumentan el uso de memoria. Establecer en 0 para indexar el contenido completo.',
                'search_include_pages'      => 'Incluir páginas en los resultados de búsqueda',
                'language'            => 'Idioma',
                'timezone'            => 'Zona horaria',
                'date_format'         => 'Formato de fecha',
                'show_reading_time'  => 'Show reading time on posts', // ENGLISH PLACEHOLDER
                'homepage'            => 'Página de inicio',
                'blog_page'           => 'Página del blog',
                'base_url'            => 'URL base',
                'favicon'             => 'Favicon',
                'apple_touch_icon'    => 'Apple touch icon', // ENGLISH PLACEHOLDER
                'og_image'            => 'Imagen Open Graph',
                'og_image_format'     => 'Formato de imagen Open Graph',
                'og_banner'           => 'Banner (predeterminado)',
                'og_square'           => 'Cuadrado',
                'sidebar_logo'        => 'Sidebar logo', // ENGLISH PLACEHOLDER
                'custom_nav'          => 'Elementos de navegación personalizados',
                'custom_nav_only'     => 'Reemplazar toda la navegación con elementos personalizados',
                'custom_routes'       => 'Rutas personalizadas',
                'homepage_default'    => 'Entradas del blog (predeterminado)',
                'blog_use_homepage'   => 'Usar página de inicio',
                'blog_hidden'         => 'Oculto (desactivar página del blog)',
                'admin_ui_section'          => 'Interfaz de administración',
                'admin_homepage'            => 'Página de inicio del administrador',
                'admin_homepage_dashboard'  => 'Panel de control',
                'admin_homepage_content'    => 'Páginas',
                'admin_hide_dashboard'      => 'Ocultar el Panel de control de la navegación',
                'enable_blog_posts'         => 'Habilitar funciones de blog (entradas, etiquetas, estadísticas)',
                'cache_section'       => 'Caché',
                'cache_enable'        => 'Activar caché de páginas',
                'rss_ttl'             => 'Duración de la caché RSS',
                'header_injects'      => 'Inyecciones de cabecera',
                'head_inject_page_label'  => 'HTML de cabecera de página',
                'head_inject_post_label'  => 'HTML de cabecera de entrada',
                'footer_injects'      => 'Inyecciones de pie de página',
                'footer_inject_page_label'=> 'HTML de pie de página',
                'footer_inject_post_label'=> 'HTML de pie de entrada',
                'notice_updated'      => 'Ajustes actualizados.',
                'error_save'          => 'Error al guardar los ajustes.',
                'error_title'         => 'El título del sitio es obligatorio.',
                'error_posts_per_page'=> 'Las entradas por página deben estar entre 1 y 100.',
                'error_timezone'      => 'La zona horaria debe ser un identificador PHP válido (p. ej. UTC, Europe/Madrid).',
                'error_date_format'   => 'El formato de fecha es obligatorio.',
                'error_homepage'      => 'La página de inicio debe referenciar una página existente.',
                'error_blog_page'     => 'La página del blog debe referenciar una página existente.',
                'search_page'         => 'Página de búsqueda',
                'search_page_none'    => 'Ninguna (desactivada)',
                'error_search_page'       => 'La página de búsqueda debe referenciar una página existente.',
                'notice_search_missing'   => 'La página de búsqueda predeterminada ("{slug}") aún no existe.',
                'notice_search_created'   => 'Página de búsqueda predeterminada creada.',
                'create_search_page'      => 'Crear página de búsqueda predeterminada',
                'dismiss_search_notice'   => 'Descartar',
                'error_og_format'     => 'El formato de imagen Open Graph debe ser banner o cuadrado.',
                'error_apple_touch_icon_type' => 'Apple touch icon must be a PNG image.', // ENGLISH PLACEHOLDER
                'current'             => 'Actual',
                'tip_language_link'   => 'código de idioma',
                'tip_timezone_link'   => 'lista de zonas horarias PHP',
                'tip_date_format_link'=> 'documentación del formato de fecha PHP',
                'tip_favicon'         => '512px funciona mejor para cuadrado',
                'tip_apple_touch_icon'=> 'Must be square and PNG format, recommended 360x360px', // ENGLISH PLACEHOLDER
                'tip_sidebar_logo'    => '124x34px works best', // ENGLISH PLACEHOLDER
                'tip_og_image'        => 'recomendado 1360x712px para banner, o 1200x1200px para cuadrado',
                'tip_og_image_dynamic' => 'Dejar en blanco para usar banners generados dinámicamente.',
                'tip_og_image_doc_link'=> 'Leer la documentación',
                'tip_one_per_line'    => 'uno por línea',
                'tip_optional'        => 'opcional',
                'tip_rss_ttl'         => 'segundos, 0 para desactivar',
            ],

            'user' => [
                'page_title'           => 'Ajustes de usuario - Pure Blog',
                'heading'              => 'Ajustes de usuario',
                'section_account'      => 'Cuenta',
                'username'             => 'Nombre de usuario',
                'section_password'     => 'Cambiar contraseña',
                'current_password'     => 'Contraseña actual',
                'new_password'         => 'Nueva contraseña',
                'confirm_password'     => 'Confirmar nueva contraseña',
                'notice_updated'       => 'Ajustes actualizados.',
                'error_save'           => 'Error al guardar los ajustes.',
                'error_username'       => 'El nombre de usuario es obligatorio.',
                'error_password_match' => 'La nueva contraseña y la confirmación no coinciden.',
                'error_password_wrong' => 'La contraseña actual es incorrecta.',
                'mfa_section_title'             => 'Autenticación de dos factores',
                'mfa_status_enabled'            => 'Activo',
                'mfa_enabled_desc'              => 'La autenticación de dos factores está activa en tu cuenta.',
                'mfa_disabled_desc'             => 'Añade una capa adicional de seguridad a tu cuenta solicitando un código de 6 dígitos de una aplicación de autenticación al iniciar sesión.',
                'mfa_setup_btn'                 => 'Configurar autenticación de dos factores',
                'mfa_setup_instructions'        => 'Escanea el código QR con tu aplicación de autenticación (como 1Password, Google Authenticator, Bitwarden o Aegis) e introduce el código de verificación de 6 dígitos a continuación.',
                'mfa_manual_entry_intro'        => 'O introduce esta clave secreta manualmente en tu aplicación:',
                'mfa_enter_code_label'          => 'Código de verificación',
                'mfa_activate_btn'              => 'Verificar y activar',
                'mfa_cancel_btn'                => 'Cancelar configuración',
                'mfa_notice_enabled'            => 'La autenticación de dos factores se ha habilitado correctamente.',
                'mfa_notice_disabled'           => 'La autenticación de dos factores se ha deshabilitado.',
                'mfa_notice_codes_regenerated'  => 'Nuevos códigos de recuperación generados.',
                'mfa_error_verify_code'         => 'Código de verificación no válido. Por favor, comprueba tu aplicación de autenticación e inténtalo de nuevo.',
                'mfa_error_password'            => 'Contraseña incorrecta.',
                'mfa_backup_codes_heading'      => 'Importante: Guarda tus códigos de recuperación',
                'mfa_backup_codes_warning'      => 'Guarda estos códigos en un lugar seguro. Si pierdes el acceso a tu aplicación de autenticación, cada código puede utilizarse una vez para iniciar sesión.',
                'mfa_regenerate_codes_title'    => 'Generar nuevos códigos de recuperación',
                'mfa_regenerate_codes_btn'      => 'Generar nuevos códigos',
                'mfa_disable_title'             => 'Deshabilitar autenticación de dos factores',
                'mfa_disable_btn'               => 'Deshabilitar 2FA',
                'mfa_confirm_password_label'    => 'Confirma tu contraseña actual',
                'mfa_copy'                      => 'Copiar',
                'mfa_copy_codes'                => 'Copiar todos los códigos',
                'mfa_copied'                    => '¡Copiado!',
            ],

            'theme' => [
                'page_title'          => 'Ajustes de tema - Pure Blog',
                'heading'             => 'Ajustes de tema y diseño',
                'notice_updated'      => 'Ajustes actualizados.',
                'notice_theme_applied'=> 'Theme applied.', // ENGLISH PLACEHOLDER
                'notice_theme_created'=> 'Theme created and saved to your collection.', // ENGLISH PLACEHOLDER
                'notice_theme_imported'=> 'Theme imported successfully.', // ENGLISH PLACEHOLDER
                'notice_theme_deleted'=> 'Theme deleted.', // ENGLISH PLACEHOLDER
                'notice_preview_exited'=> 'Theme preview exited.', // ENGLISH PLACEHOLDER
                'error_save'          => 'Error al guardar los ajustes.',
                'error_font'          => 'El tipo de fuente debe ser sans, serif o mono.',
                'error_admin_font'    => 'El tipo de fuente del administrador debe ser sans, serif o mono.',
                'error_admin_color'   => 'El modo de color del administrador debe ser claro, oscuro o automático.',
                'error_color_mode'    => 'El modo de color debe ser claro, oscuro o automático.',
                'error_post_layout'   => 'El diseño de lista de entradas debe ser extracto, completo o archivo.',
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
                'section_fonts'       => 'Ajustes de fuente',
                'site_font'           => 'Fuente del sitio',
                'admin_font'          => 'Fuente del admin',
                'section_color_mode'  => 'Modo de color',
                'site_color_mode'     => 'Modo de color del sitio',
                'admin_color_mode'    => 'Modo de color del admin',
                'color_light'         => 'Claro',
                'color_dark'          => 'Oscuro',
                'color_auto'          => 'Auto',
                'light_mode'          => 'Modo claro',
                'dark_mode'           => 'Modo oscuro',
                'color_background'    => 'Color de fondo',
                'color_text'          => 'Color de texto',
                'color_accent'        => 'Color de acento',
                'color_border'        => 'Color de borde',
                'color_accent_bg'     => 'Fondo de acento',
                'section_post_layout' => 'Diseño de lista de entradas',
                'layout_excerpt'      => 'Extracto de entrada',
                'layout_full'         => 'Entrada completa',
                'layout_archive'      => 'Fecha y título',
            ],

            'css' => [
                'page_title'        => 'CSS personalizado - Pure Blog',
                'heading'           => 'Ajustes de CSS personalizado',
                'notice_saved'      => 'CSS personalizado guardado.',
                'error_save_front'  => 'Error al guardar el CSS del frontend.',
                'error_save_admin'  => 'Error al guardar el CSS del admin.',
                'error_dir_front'   => 'No se pudo crear el directorio CSS del frontend.',
                'error_dir_admin'   => 'No se pudo crear el directorio CSS del admin.',
                'section_front'     => 'CSS del frontend',
                'section_admin'     => 'CSS del admin',
                'placeholder_front' => 'Añade CSS personalizado a tu sitio web...',
                'placeholder_admin' => 'Añade CSS personalizado al CMS Pure Blog...',
            ],



        ],

    ],


];
