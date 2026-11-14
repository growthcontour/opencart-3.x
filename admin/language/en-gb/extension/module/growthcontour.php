<?php
// Heading
$_['heading_title']        = 'Growth contour';

// General
$_['text_extension']       = 'Extensions';
$_['text_success']         = 'Growth contour settings have been saved.';
$_['text_edit']            = 'Growth contour Settings';
$_['text_enabled']         = 'Enabled';
$_['text_disabled']        = 'Disabled';
$_['text_none']            = '--- None ---';
$_['text_ok']              = 'Done';
$_['text_never']           = 'Never';

// Tabs
$_['tab_settings']         = 'Settings';
$_['tab_orders']           = 'Orders';
$_['tab_cart']             = 'Abandoned Carts';
$_['tab_catalog']          = 'Catalog';
$_['tab_support']          = 'Support';
$_['tab_logs']             = 'Logs';

// Buttons
$_['button_copy']          = 'Copy';
$_['button_regenerate']    = 'Generate New Key';
$_['button_revoke_old']    = 'Complete Key Rotation';
$_['button_sync_statuses'] = 'Fetch Statuses from System';
$_['button_sync_all']      = 'Send All Orders to System';
$_['button_refresh_logs']  = 'Refresh';
$_['button_clear_logs']    = 'Clear Logs';
$_['button_cancel_sync']   = 'Cancel';

// ─── Settings ───
$_['entry_status']             = 'Module';
$_['entry_domain']             = 'System URL';
$_['placeholder_domain']       = 'https://demo.growthcontour.com';
$_['help_domain']              = 'The URL of your Growth contour system, e.g. https://demo.growthcontour.com';
$_['entry_token']              = 'System Access Token';
$_['help_token']               = 'The token issued by Growth contour for this store (Settings → Tokens). The module uses it to send orders and carts and to start catalog synchronization. The token must have the "Read Data" permission to start synchronization.';
$_['entry_integration']        = 'Integration ID';
$_['placeholder_integration']  = '1';
$_['help_integration']         = 'The ID of this store\'s integration in Growth contour (Settings → Integrations).';
$_['entry_inbound_token']      = 'System-to-Website Access Token';
$_['help_inbound_token']       = 'Growth contour uses this token to communicate with your website and send status changes and catalog data. Copy it into the integration settings in Growth contour (the "Website Access Token" field). It is generated automatically when the module is installed.';
$_['text_rotation_active']     = 'Key rotation is in progress: the old key is still accepted. Once the new key has been entered in the system, click "Complete Key Rotation".';
$_['text_confirm_regenerate']  = 'Generate a new key? The old key will continue working until you complete the rotation, but the new key must be entered in Growth contour.';
$_['text_token_regenerated']   = 'A new key has been generated. Enter it in the integration settings in Growth contour.';
$_['text_confirm_revoke_old']  = 'Revoke the old key? After this, only the new key will be accepted.';
$_['text_old_revoked']         = 'The old key has been revoked. Key rotation is complete.';

// ─── Orders ───
$_['entry_send_status']     = 'Send Orders to System';
$_['help_send_status']      = 'Every new order and every order status change on the website is sent to Growth contour immediately.';
$_['entry_sync_all']        = 'Existing Orders';
$_['help_sync_all']         = 'Sends all existing website orders to the system in batches. Sending them again will not create duplicates.';
$_['text_confirm_sync_all'] = 'Send all orders to Growth contour? This may take some time for a large database.';
$_['text_sync_all_done']    = 'Done. All orders have been sent.';
$_['entry_status_map']      = 'Statuses: Website → System';
$_['help_status_map']       = 'Click "Fetch Statuses from System", then select the corresponding system status for each website status.';
$_['text_pull_success']     = 'Statuses fetched: %s.';
$_['entry_status_map_in']   = 'Statuses: System → Website';
$_['help_status_map_in']    = 'When an order status changes in Growth contour, the corresponding status from this table is applied on the website.';
$_['entry_paid_statuses']   = 'Paid Statuses';
$_['help_paid_statuses']    = 'Select the website statuses that indicate an order has been paid. Payment status is sent to the system.';
$_['column_oc_status']      = 'Website Status';
$_['column_crm_status']     = 'System Status';

// ─── Abandoned Carts ───
$_['entry_cart_enabled']         = 'Send Abandoned Carts';
$_['help_cart_enabled']          = 'Abandoned carts are sent to Growth contour on a schedule to enable customer reminders.';
$_['entry_cart_threshold']       = 'Mark Cart as Abandoned After (min)';
$_['help_cart_threshold']        = 'How many minutes of inactivity must pass before a cart is considered abandoned.';
$_['entry_cart_max_age']         = 'Maximum Cart Age (hours)';
$_['help_cart_max_age']          = 'Older carts are not sent.';
$_['entry_cart_min_amount']      = 'Minimum Cart Amount';
$_['help_cart_min_amount']       = 'Carts with a lower total are not sent.';
$_['entry_cart_batch']           = 'Carts per Run';
$_['help_cart_batch']            = 'How many carts to process during one scheduler run (1–200).';
$_['entry_cart_guests']          = 'Guest Carts';
$_['help_cart_guests']           = 'Send carts from customers without an account, provided contact details have been entered.';
$_['entry_cart_require_contact'] = 'Require Contact Details';
$_['help_cart_require_contact']  = 'Send only carts that contain an email address or phone number.';
$_['entry_cart_require_consent'] = 'Require Marketing Consent';
$_['help_cart_require_consent']  = 'Send only carts where the customer has agreed to receive messages.';
$_['entry_cart_cron']            = 'Scheduler URL';
$_['help_cart_cron']             = 'Add a request to this URL to your hosting scheduler (cron), using the system-to-website access token. The example below runs every 10 minutes.';
$_['stat_total']                 = 'Total';
$_['stat_open']                  = 'Active';
$_['stat_sent']                  = 'Sent';
$_['stat_recovered']             = 'Recovered';
$_['stat_expired']               = 'Expired';
$_['stat_recovery_rate']         = 'Recovery Rate';

// ─── Catalog ───
$_['tab_cat_products']             = 'Products';
$_['tab_cat_categories']           = 'Categories';
$_['tab_cat_attributes']           = 'Attributes';
$_['tab_cat_reviews']              = 'Reviews';
$_['tab_cat_customers']            = 'Customers';

$_['button_sync_catalog_all']      = 'Synchronize All';
$_['button_sync_products']         = 'Synchronize Products';
$_['button_sync_categories']       = 'Synchronize Categories';
$_['button_sync_attributes']       = 'Synchronize Attributes';
$_['button_sync_reviews']          = 'Synchronize Reviews';
$_['button_sync_customers']        = 'Synchronize Customers';

$_['entry_sync_enabled']           = 'Synchronization';
$_['entry_sync_match']             = 'Matching';
$_['entry_sync_create']            = 'Create New';
$_['entry_sync_update']            = 'Update Existing';
$_['entry_sync_missing']           = 'Missing from System';
$_['entry_sync_sku']               = 'Website SKU Field';
$_['entry_sync_fields']            = 'Fields to Update in Existing Products';
$_['entry_sync_moderation']        = 'Moderation from System';
$_['entry_sync_auto']              = 'Automatically';
$_['entry_sync_manual']             = 'Manually';
$_['entry_sync_batch']              = 'Batch Size';
$_['entry_sync_cron']               = 'Catalog Scheduler';

$_['help_sync_products']           = 'The system transfers complete product data: names and descriptions in all languages, SEO URLs, price, stock quantity, SKU, EAN, weight, brand, categories, options, attributes, and all images.';
$_['help_sync_categories']         = 'The system transfers the category tree, including names, H1 headings, descriptions, meta tags, SEO URLs, images, status, sort order, and menu visibility.';
$_['help_sync_attributes']         = 'The system transfers attribute groups and attribute names. Product attribute values are transferred along with the products.';
$_['help_sync_reviews']            = 'First, the system retrieves new reviews from the website, then sends the reviews selected for this website in the system (at the scheduled time) without creating duplicates. A review unpublished in the system is deleted from the website. Enable "Create New" so system reviews appear on the website.';
$_['help_sync_customers']          = 'The system transfers customer names, email addresses, phone numbers, marketing consent, and addresses. Passwords are not transferred; customers can sign in using "Forgotten Password".';
$_['help_sync_reviews_moderation'] = 'Approving, rejecting, or deleting a review in the system enables or disables that review on the website.';
$_['help_sync_sku']                = 'The product field on the website that stores the SKU. If it is empty, another field is used.';
$_['help_sync_fields']             = 'Applies only to existing products. New products and "Full Overwrite" always write all data.';
$_['help_sync_match']              = 'How to find the website record corresponding to a system record. "By System ID" means the website ID must match the system ID.';
$_['help_sync_missing']            = 'What to do with records that were previously received from the system but are no longer present there. This action is performed only after a full synchronization completes without errors.';
$_['help_sync_batch']              = 'How many records the system transfers in one request (10–500). Use a smaller value for weaker hosting or a larger value for faster synchronization.';
$_['help_sync_manual']             = 'Starts synchronization using the saved settings. Click "Save" first after making changes.';
$_['help_sync_cron']               = 'Automatic synchronization works only if this command is added to your hosting scheduler (cron) and runs every 5 minutes. The interval for each section is configured in the scheduler.';

$_['text_match_map']               = 'Use Saved Links Only';
$_['text_match_sku']               = 'By SKU (link to existing website records)';
$_['text_match_name']              = 'By Name (link to existing website records)';
$_['text_match_email']             = 'By Email (link to existing website records)';
$_['text_match_id']                = 'By System ID (website ID = system ID; existing records with that ID are overwritten)';
$_['text_missing_disable']         = 'Disable on Website';
$_['text_missing_delete']          = 'Delete from Website';
$_['text_missing_keep']            = 'Leave Unchanged';
$_['text_missing_out_of_stock']    = 'Out of Stock (quantity 0; product remains on website)';
$_['text_sku_model']               = 'Model (model)';
$_['text_sku_sku']                 = 'SKU (sku)';

$_['text_field_price']             = 'Price';
$_['text_field_quantity']          = 'Stock Quantity';
$_['text_field_status']            = 'Status (enabled/disabled)';
$_['text_field_content']            = 'Name, descriptions, SEO, images, brand';
$_['text_field_categories']         = 'Product Categories';
$_['text_field_options']            = 'Product Options (size, color, etc.)';
$_['text_field_attributes']        = 'Product Attributes';

$_['text_opt_force']               = 'Full Overwrite';
$_['text_opt_resume']              = 'Resume Interrupted Job';
$_['text_opt_wipe']                = 'Delete All Website Data Before Import';
$_['text_wipe_prompt']             = 'WARNING! All data in this section on the website will be permanently deleted. Enter DELETE to confirm:';
$_['text_confirm_sync_catalog_all']= 'Start synchronization of categories, attributes, products, reviews, and customers?';

$_['text_auto_off']                = 'Disabled';
$_['text_auto_15']                 = 'Every 15 Minutes';
$_['text_auto_60']                 = 'Every Hour';
$_['text_auto_360']                = 'Every 6 Hours';
$_['text_auto_1440']               = 'Once a Day';

$_['text_run_started']             = 'started';
$_['text_run_busy']                = 'already running';
$_['text_run_not_supported']       = 'not supported by the system';

$_['column_entity']                = 'Data';
$_['column_in_shop']               = 'On Website';
$_['column_status']                = 'Status';
$_['column_progress']              = 'Progress';
$_['column_counters']              = 'Results';
$_['column_speed']                 = 'Speed / Time Remaining';

$_['text_job_queued']              = 'queued';
$_['text_job_running']             = 'running';
$_['text_job_done']                = 'completed';
$_['text_job_cancelled']           = 'cancelled';
$_['text_job_error']               = 'error';
$_['text_job_stalled']             = 'stalled (resume)';
$_['text_job_not_started']         = 'Synchronization did not start: the system could not begin synchronization with the website. Check that the section is enabled and its settings have been saved.';

$_['text_cnt_created']             = 'created';
$_['text_cnt_updated']             = 'updated';
$_['text_cnt_unchanged']           = 'unchanged';
$_['text_cnt_skipped']             = 'skipped';
$_['text_cnt_errors']              = 'errors';
$_['text_cnt_pruned']              = 'removed missing records';
$_['text_per_sec']                 = 'rec/s';
$_['text_unit_h']                  = 'h';
$_['text_unit_m']                  = 'min';
$_['text_unit_s']                  = 's';

// ─── Logs ───
$_['text_no_logs']         = 'There are no log entries yet.';
$_['text_clear_success']   = 'Logs cleared.';
$_['text_confirm_clear']   = 'Clear the entire log?';
$_['column_time']          = 'Time';
$_['column_event']         = 'Event';
$_['column_order']         = 'Order';
$_['column_http']          = 'HTTP';
$_['column_result']        = 'Result';
$_['column_message']       = 'Message';
$_['text_log_ok']          = 'OK';
$_['text_log_dup']         = 'DUPLICATE';
$_['text_log_fail']        = 'ERROR';

// ─── Support: Module Description ───
$_['text_about_title']   = 'Growth contour for OpenCart 3';
$_['text_about_intro']   = 'The module connects your online store to the Growth contour system. Orders, payments, and abandoned carts are sent to the system automatically, while the catalog, reviews, and customers are transferred from the system to the website. The system is the primary source of data, and the website receives up-to-date information from it.';

$_['icon_about_orders']        = 'fa-shopping-cart';
$_['text_about_orders_title']  = 'Orders';
$_['text_about_orders']        = '<ul><li>Every new order is sent to the system immediately, including the customer, contact details, address, shipping, payment, products with options, discounts, comments, and UTM tags.</li><li>Sending an order again does not create duplicates.</li><li>Existing orders can be sent in batches with a single button.</li></ul>';

$_['icon_about_statuses']        = 'fa-exchange';
$_['text_about_statuses_title']  = 'Two-Way Status Synchronization';
$_['text_about_statuses']        = '<ul><li>Website statuses are mapped to system statuses.</li><li>Status changes on the website are sent to the system, and status changes in the system are sent to the website.</li></ul>';

$_['icon_about_payments']        = 'fa-credit-card';
$_['text_about_payments_title']  = 'Payments';
$_['text_about_payments']        = '<ul><li>Selected statuses are treated as "paid", so the system immediately knows that an order has been paid.</li></ul>';

$_['icon_about_cart']        = 'fa-shopping-basket';
$_['text_about_cart_title']  = 'Abandoned Carts';
$_['text_about_cart']        = '<ul><li>The module records customer carts and contact details entered during checkout.</li><li>Abandoned carts are sent to the system for reminders, with configurable inactivity thresholds, minimum amounts, guest cart handling, and contact or marketing consent requirements.</li><li>The reminder link restores the customer\'s cart on the website. Once an order is placed, the cart is automatically marked as recovered.</li></ul>';

$_['icon_about_catalog']        = 'fa-cubes';
$_['text_about_catalog_title']  = 'Catalog: System to Website';
$_['text_about_catalog']        = '<ul><li><b>Categories</b> — category tree, names, H1 headings, descriptions, meta tags, SEO URLs, images, status, sort order, and menu.</li><li><b>Products</b> — names and descriptions in all languages, SEO URLs, price, stock quantity, SKU, EAN, weight, brand, categories, and all images.</li><li><b>Options</b> — type, value, price, quantity, weight, and points; percentage markups are converted into amounts.</li><li><b>Attributes</b> — groups, names, and product values in every language, including lists, numbers with units, dates, and text.</li><li><b>Reviews</b> — reviews appear on the website without duplicates; moderation in the system enables or disables them.</li><li><b>Customers</b> — name, email, phone, marketing consent, and addresses with country and region. The website customer ID is sent back to the system.</li><li>For ocStore, H1 headings and the main product category are populated.</li></ul>';

$_['icon_about_modes']        = 'fa-sliders';
$_['text_about_modes_title']  = 'Synchronization Modes';
$_['text_about_modes']        = '<ul><li><b>Matching</b>: by system ID (website ID = system ID), SKU, name, email, or saved links only.</li><li><b>Create New</b> and <b>Update Existing</b> can be configured separately for each section.</li><li><b>Missing from System</b> — disable, delete, or leave unchanged.</li><li><b>Full Overwrite</b> — overwrite all data, even if it has not changed.</li><li><b>Delete All Website Data Before Import</b> — clear demo data before the first import.</li><li>Only changed records are transferred, so subsequent synchronizations take seconds.</li></ul>';

$_['icon_about_progress']        = 'fa-tasks';
$_['text_about_progress_title']  = 'Progress, Cancellation, and Resuming';
$_['text_about_progress']        = '<ul><li>Synchronization runs in batches and can scale to millions of products.</li><li>The table displays progress, created, updated and skipped records, errors, speed, and estimated time remaining.</li><li>Synchronization can be cancelled and resumed from where it stopped.</li><li>Interrupted batches are retried automatically.</li></ul>';

$_['icon_about_auto']        = 'fa-clock-o';
$_['text_about_auto_title']  = 'Automatic Scheduling';
$_['text_about_auto']        = '<ul><li>Each section has its own interval: 15 minutes, one hour, six hours, or one day.</li><li>Runs through the hosting scheduler.</li></ul>';

$_['icon_about_security']        = 'fa-shield';
$_['text_about_security_title']  = 'Security';
$_['text_about_security']        = '<ul><li>Data exchange is secured with access tokens in both directions and digital request signatures with replay protection.</li><li>The access token can be rotated without downtime: the old token remains valid until you complete the rotation.</li><li>Images are downloaded only from your system\'s URL.</li></ul>';

$_['icon_about_logs']        = 'fa-list-alt';
$_['text_about_logs_title']  = 'Logs';
$_['text_about_logs']        = '<ul><li>Every order transfer is recorded in the log along with its result and any error message.</li></ul>';

$_['text_start_title']  = 'Quick Start';
$_['text_start_steps']  = '<ol><li>In Growth contour, create an integration for the OpenCart platform and enter your website URL.</li><li>Copy the "System-to-Website Access Token" from the module and paste it into the integration settings in Growth contour.</li><li>In the system, create a token for this integration and paste it into the module\'s "System Access Token" field. Enter the system URL and integration ID.</li><li>On the "Orders" tab, enable order transfer and map the statuses.</li><li>On the "Catalog" tab, enable the required sections, select the synchronization modes, and click "Synchronize All".</li></ol>';

$_['text_about_version'] = 'Growth contour 2.1.0 for OpenCart 3.0.x / ocStore 3';

// ─── Support: Contacts ───
$_['text_contacts_title']          = 'Contact Us';

$_['icon_contact_sales']           = 'fa-handshake-o';
$_['text_contact_sales_title']     = 'Orders and Partnerships';
$_['text_contact_sales_text']      = 'Configuration, development, partnerships, and general enquiries.';
$_['text_contact_sales_email']     = 'manager@growthcontour.com';

$_['icon_contact_support']         = 'fa-life-ring';
$_['text_contact_support_title']   = 'Support';
$_['text_contact_support_text']    = 'Questions about using and configuring the system.';
$_['text_contact_support_email']   = 'support@growthcontour.com';

$_['icon_contact_bugs']            = 'fa-bug';
$_['text_contact_bugs_title']      = 'Report a Bug';
$_['text_contact_bugs_text']       = 'Something not working? Contact our developers directly.';
$_['text_contact_bugs_email']      = 'bugs@growthcontour.com';

$_['icon_contact_security']        = 'fa-lock';
$_['text_contact_security_title']  = 'Security';
$_['text_contact_security_text']   = 'Found a vulnerability? Please report it to us confidentially before public disclosure.';
$_['text_contact_security_email']  = 'security@growthcontour.com';

// ─── Errors ───
$_['error_permission']     = 'You do not have permission to modify Growth contour settings.';
$_['error_credentials']    = 'First, enter the "System URL" and "System Access Token" on the "Settings" tab and save your changes.';
$_['error_pull']           = 'Failed to fetch statuses (HTTP %s): %s';
$_['error_wipe_confirm']   = 'Deletion has not been confirmed.';
$_['error_connection']     = 'Unable to connect to the server.';
$_['error_generic']        = 'Error.';

// ─── Import to System ───
$_['tab_import']                 = 'Import to System';
$_['text_imp_intro']             = 'Import products from this website into Growth contour. Start with "Dry Run" to see exactly what will be transferred and how. Then perform a "Trial" import on a few products (it can be fully rolled back), and only then run the "Full" import.';
$_['text_imp_settings']          = 'What and How to Import';
$_['entry_imp_mode']             = 'Mode';
$_['text_imp_mode_dry']          = 'Dry Run';
$_['text_imp_mode_trial']        = 'Trial (N Products)';
$_['text_imp_mode_full']         = 'Full';
$_['help_imp_mode']              = 'A dry run does not write any data: it matches products, validates them against all product rules, and displays the results. A trial import actually imports the specified number of products and can be rolled back with a single button after verification.';
$_['entry_imp_limit']            = 'Quantity / IDs';
$_['placeholder_imp_ids']        = 'Comma-separated website product IDs (optional)';
$_['help_imp_limit']             = 'How many products to process (0 = all). If IDs are specified, only those website products will be processed.';
$_['entry_imp_match']            = 'Find Product in System';
$_['column_imp_site_field']      = 'Website Field';
$_['column_imp_crm_field']       = 'System Field';
$_['button_imp_add_rule']        = 'Add Rule';
$_['text_imp_rule_link']         = 'Existing Link';
$_['help_imp_match']             = 'Rules are checked from top to bottom. The first rule that finds exactly one product is applied. If multiple products are found, the row is marked as an error and the product is not changed. This allows a second website with the same products to link to existing records instead of creating duplicates.';
$_['error_imp_rules']            = 'Add at least one matching rule.';
$_['entry_imp_new']              = 'Not Found in System';
$_['text_imp_new_create']        = 'Create New Products';
$_['text_imp_new_skip']          = 'Skip';
$_['text_imp_status_draft']      = 'Status: Draft';
$_['text_imp_status_active']     = 'Status: Active';
$_['text_imp_status_site']       = 'Status: Same as Website';
$_['text_imp_sku_sku']           = 'System SKU = Website SKU';
$_['text_imp_sku_model']         = 'System SKU = Website Model';
$_['text_imp_sku_none']          = 'SKU: Use System Settings';
$_['entry_imp_match_found']      = 'Found in System';
$_['text_imp_match_link']        = 'Link Only';
$_['text_imp_match_update']      = 'Link and Update';
$_['text_imp_upd_price']         = 'price';
$_['text_imp_upd_stock']         = 'stock quantity';
$_['text_imp_upd_status']        = 'status';
$_['text_imp_upd_content']       = 'Product Content:';
$_['text_imp_upd_names']         = 'names, descriptions, SEO';
$_['text_imp_upd_codes']         = 'codes (model, EAN, etc.)';
$_['text_imp_upd_brand']         = 'manufacturer';
$_['text_imp_upd_categories']    = 'categories';
$_['text_imp_upd_images']        = 'images';
$_['text_imp_upd_attributes']    = 'attributes';
$_['text_imp_upd_options']       = 'options';
$_['text_imp_content_fill']      = 'Fill Empty Fields Only';
$_['text_imp_content_overwrite'] = 'Replace with Website Data (categories are added)';
$_['entry_imp_content']          = 'Dictionaries and Images';
$_['text_imp_cat_path']          = 'Categories: Match by Path or Create';
$_['text_imp_cat_match']         = 'Categories: Match Existing Only';
$_['text_imp_cat_none']          = 'Categories: Do Not Import';
$_['text_imp_brand_create']      = 'Manufacturers: Find or Create';
$_['text_imp_brand_match']       = 'Manufacturers: Find Existing Only';
$_['text_imp_brand_none']        = 'Manufacturers: Do Not Import';
$_['text_imp_images']            = 'Download Images';
$_['text_imp_link_site']         = 'Save Links on Website';
$_['entry_imp_dict']             = 'Attributes and Options';
$_['text_imp_attr_create']       = 'Attributes: Find by Name or Create';
$_['text_imp_attr_match']        = 'Attributes: Match Existing Only';
$_['text_imp_attr_none']         = 'Attributes: Do Not Import';
$_['text_imp_opt_create']        = 'Options: Find by Name or Create';
$_['text_imp_opt_match']         = 'Options: Match Existing Only';
$_['text_imp_opt_none']          = 'Options: Do Not Import';
$_['text_imp_related']           = 'Related Products';
$_['entry_imp_warehouse']        = 'Stock Warehouse';
$_['text_imp_no_warehouse']      = '— Do Not Import Stock —';
$_['entry_imp_langs']            = 'Languages';
$_['entry_imp_groups']           = 'Customer Groups';
$_['help_imp_groups']            = 'Used for promotions, quantity-based discounts, and reward points. * indicates the website\'s default group (usually "All Customers").';
$_['text_imp_auto']              = 'Automatically';
$_['text_imp_group_all']         = 'All Customers';
$_['text_imp_group_skip']        = '— Do Not Import —';
$_['button_imp_start']           = 'Start';
$_['button_imp_save']            = 'Save Settings';
$_['text_imp_saved']             = 'Import settings have been saved.';
$_['text_imp_started']           = 'Started, job #%s';
$_['text_imp_confirm_full']      = 'Start a full import of all website products into the system?';
$_['text_imp_confirm_rollback']  = 'Roll back this job? Created products will be moved to the trash, updated prices / stock quantities / statuses will be restored, and new links and empty categories and manufacturers created during the import will be deleted.';
$_['text_imp_progress']          = 'Progress and History';
$_['button_imp_cancel']           = 'Stop';
$_['button_imp_resume']           = 'Resume';
$_['button_imp_rollback']         = 'Roll Back';
$_['button_imp_report']           = 'Report';
$_['button_imp_refresh']          = 'Refresh';
$_['column_imp_mode']             = 'Mode';
$_['column_imp_status']           = 'Status';
$_['column_imp_processed']        = 'Processed';
$_['column_imp_result']           = 'Result';
$_['column_imp_date']             = 'Created';
$_['text_imp_st_queued']          = 'queued';
$_['text_imp_st_running']         = 'running';
$_['text_imp_st_done']             = 'completed';
$_['text_imp_st_failed']           = 'failed';
$_['text_imp_st_cancelled']        = 'stopped';
$_['text_imp_st_interrupted']      = 'interrupted';
$_['text_imp_st_rolling_back']     = 'rolling back…';
$_['text_imp_st_rolled_back']      = 'rolled back';
$_['text_imp_cnt_created']         = 'created';
$_['text_imp_cnt_linked']          = 'linked';
$_['text_imp_cnt_updated']         = 'updated';
$_['text_imp_cnt_skipped']         = 'skipped';
$_['text_imp_cnt_errors']          = 'errors';
$_['text_imp_cnt_warnings']        = 'with warnings';
$_['text_imp_report']              = 'Product Report';
$_['text_imp_all']                 = 'All';
$_['text_imp_act_create']          = 'New';
$_['text_imp_act_link']            = 'Link';
$_['text_imp_act_update']          = 'Update';
$_['text_imp_act_skip']            = 'Skip';
$_['text_imp_act_error']           = 'Error';
$_['text_imp_warnings']            = 'Warnings';
$_['placeholder_imp_search']      = 'ID, SKU, or Name';
$_['column_imp_site_id']          = 'Website ID';
$_['column_imp_sku']              = 'SKU';
$_['column_imp_name']             = 'Name';
$_['column_imp_action']           = 'Action';
$_['column_imp_matched']          = 'Matched By';
$_['column_imp_crm_id']           = 'System Product';
$_['column_imp_message']          = 'Details';
$_['text_imp_preview']            = 'How It Will Appear in the System';
$_['text_imp_none']               = 'No Data';
$_['error_imp_scope']             = 'The access token does not have permission to "Import Products from Website". Enable this permission in the system under Settings → Tokens.';

// ─── Support: extra blocks ───
$_['icon_about_safe']            = 'fa-power-off';
$_['text_about_safe_title']      = 'Safe Start';
$_['text_about_safe']            = '<ul><li>After installation everything is disabled: orders, carts and catalog exchange do not work, and nothing changes on the website.</li><li>Enable the sections you need and click "Save" at the top of the page — all tabs are saved at once, without reloading.</li><li>"Missing from System" defaults to "Leave Unchanged": website products are neither disabled nor deleted.</li></ul>';
$_['icon_about_import']          = 'fa-download';
$_['text_about_import_title']    = 'Importing Website Products into the System';
$_['text_about_import']          = '<ul><li>Transfers existing website products into the system: names, descriptions, SEO, prices, stock, codes, manufacturers, categories, images, attributes, options and related products.</li><li>Products are matched in the system by rules (link, SKU, model, EAN…) — a second website with the same products links to existing records instead of creating duplicates.</li><li>"Dry Run" shows the result in advance; a "Trial" import on a few products can be rolled back with one button.</li><li>A report for every product: what was created, linked, updated or skipped, and why.</li></ul>';
$_['icon_about_delivery']        = 'fa-truck';
$_['text_about_delivery_title']  = 'Delivery';
$_['text_about_delivery']        = '<ul><li>At checkout the module saves a delivery snapshot: order, session and form fields — even if the shipping module (including an encrypted one) does not write them into the order.</li><li>Parsing rules determine the carrier, service type, city, branch or parcel locker. For Nova Poshta the ref is looked up in the website\'s Nova Poshta module tables.</li><li>Rules can be tested on any order, and existing orders can be re-parsed.</li></ul>';

// ─── Connection Check (Settings tab) ───
$_['entry_check_connection']   = 'Connection to System';
$_['button_check_connection']  = 'Check Connection';
$_['help_check_connection']    = 'Checks the system URL and access token from the fields above (works before saving): whether the system responds, whether the token is accepted and whether this website is allowed.';
$_['text_check_ok']            = 'Connected: the system responded in %s ms, the token was accepted. Order statuses in the system: %s.';
$_['error_check_empty']        = 'Fill in "System URL" and "System Access Token".';
$_['error_check_domain']       = 'The system URL must start with https:// (e.g. https://demo.growthcontour.com).';
$_['error_check_network']      = 'Cannot connect to %s: %s. Check the system URL and whether the website server can access the internet (firewall, SSL certificate).';
$_['error_check_auth']         = 'The system rejected the request (HTTP %s): %s. Check the access token, its permissions and that this website\'s address is in the token\'s allowed domains.';
$_['error_check_404']          = 'No system found at %s (HTTP 404). Check the URL — without a path at the end.';
$_['error_check_http']         = 'The system returned HTTP error %s: %s';

// ─── Send One Order (Orders tab) ───
$_['entry_send_order']         = 'Single Order';
$_['placeholder_send_order']   = 'Order ID';
$_['button_send_order']        = 'Send';
$_['help_send_order']          = 'Sends the website order with this ID to the system — new or again (after fixing an error). No duplicates are created.';
$_['text_send_order_ok']       = 'Order #%s was sent and accepted by the system.';
$_['text_send_order_dup']      = 'Order #%s already exists in the system — data updated, no duplicate created.';
$_['error_send_order_missing'] = 'Order #%s was not found on the website.';
$_['error_send_order_push']    = 'The website could not start the transfer (HTTP %s). Details: %s. HTTP 401 — the "System-to-Website Access Token" does not match; HTTP 0 — the server cannot reach itself (DNS, Cloudflare, firewall, SSL) — send the details to your hosting provider.';
$_['error_send_order_nolog']   = 'Order #%s was not sent: the module did not contact the system. Check the "Order Transfer Check" items above.';
$_['error_send_order_failed']  = 'Order #%s was not accepted (HTTP %s): %s';

// ─── Order Transfer Check (Orders tab) ───
$_['text_diag_title']      = 'Order Transfer Check';
$_['diag_module_ok']       = 'The module is enabled.';
$_['diag_module_bad']      = 'The module is disabled — enable it on the "Settings" tab and click "Save".';
$_['diag_orders_ok']       = 'Order transfer is enabled.';
$_['diag_orders_bad']      = 'Order transfer is disabled — enable it above and click "Save".';
$_['diag_creds_ok']        = 'System URL and System Access Token are set.';
$_['diag_creds_bad']       = '"System URL" or "System Access Token" is missing ("Settings" tab).';
$_['diag_events_ok']       = 'OpenCart order events are registered and enabled.';
$_['diag_events_bad']      = 'OpenCart events are missing or disabled: %s. Orders are not sent without them. Click "Save" — the module will restore them.';
$_['diag_files_ok']        = 'Module files are present on the website and come from the same package.';
$_['diag_files_missing']   = 'Module files are missing on the website: %s. Upload them from the module package.';
$_['diag_files_mismatch']  = 'Module files come from different packages: the website controller (catalog/controller/extension/module/growthcontour.php) calls the delivery snapshot, but the website model (catalog/model/extension/module/growthcontour.php) does not have this method. Because of this, checkout breaks when the order is sent. Upload the model from the same package.';
$_['diag_last_none']       = 'No orders have been sent yet. Place a test order on the website and click "Save" or reload the page — the result will appear here.';
$_['diag_last_ok']         = 'Last transfer: %s, order #%s — accepted by the system.';
$_['diag_last_bad']        = 'Last transfer: %s, order #%s — error: %s. Details are on the "Logs" tab.';

// ─── Delivery ───
$_['tab_delivery']               = 'Delivery';
$_['text_delivery_intro']        = 'At checkout the module saves a delivery snapshot: order fields plus session and form data related to delivery. This way data is not lost even if the shipping module (including an encrypted one) does not write it into the order. The snapshot is then parsed by the rules below: carrier, service type, city, branch / parcel locker with ref. For Nova Poshta the ref is found automatically in the website\'s Nova Poshta module tables: by a UUID in the snapshot or by number "№123" and city.';
$_['text_delivery_rules']        = 'Parsing Rules (JSON)';
$_['help_delivery_rules']        = 'An array of rules; the first one matching the shipping code (<code>shipping_code</code>) is applied.<br><code>match</code> — exact code (<code>novaposhta.poshtomat</code>) or a regular expression (<code>/^novaposhta\\./</code>);<br><code>carrier</code> — carrier (<code>novaposhta</code>); <code>service</code> — <code>warehouse</code> | <code>postomat</code> | <code>address</code> | <code>pickup</code>;<br><code>fields</code> — where to take values from: <code>area, area_ref, city, city_ref, point, point_ref, point_number, street, building, flat</code>. Source: <code>order.shipping_address_1</code>, <code>session.key.key</code>, <code>post.key</code>; with a regular expression — <code>order.shipping_address_1 | /№\s*(\d+)/u</code> (1st group); several — separated by <code>||</code> (first non-empty); constant — <code>=text</code>.<br><code>auto: false</code> — disable automatic ref lookup in Nova Poshta tables. See the paths for <code>session</code> / <code>post</code> with the "Snapshot" button in the list below.';
$_['help_delivery_save']         = 'Rules are saved with the "Save" button at the top of the page together with the other settings.';
$_['entry_delivery_test']        = 'Test on order #';
$_['button_delivery_test']       = 'Test';
$_['entry_delivery_reparse']     = 'Re-parse latest';
$_['button_delivery_reparse']    = 'Re-parse';
$_['text_delivery_recent']       = 'Latest Orders';
$_['text_delivery_all']          = 'All';
$_['text_delivery_st_ok']        = 'Complete';
$_['text_delivery_st_partial']   = 'Partial';
$_['text_delivery_st_empty']     = 'Empty';
$_['text_delivery_st_none']      = 'Not a delivery service';
$_['text_delivery_empty_list']   = 'No data';
$_['button_delivery_raw']        = 'Snapshot';
$_['column_delivery_method']     = 'Method';
$_['column_delivery_status']     = 'State';
$_['column_delivery_city']       = 'City';
$_['column_delivery_point']      = 'Branch / Address';
$_['column_delivery_found']      = 'Found By';
$_['text_delivery_saved']        = 'Rules saved. Click "Re-parse" to apply them to existing orders.';
$_['text_delivery_reparsed']     = 'Orders re-parsed: %s';
$_['error_delivery_rules']       = 'Error in rules: %s';
$_['error_delivery_order']       = 'Order not found.';

// ─── Import to System: save hint ───
$_['help_imp_save']              = 'Import settings are saved with the "Save" button at the top of the page together with the others, and automatically when the import starts.';
