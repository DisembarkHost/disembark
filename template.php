<?php
/**
 * Mount point for the Disembark dashboard. The app itself is assets/js/*.js,
 * enqueued by Run::enqueue_app_assets().
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div id="disembark-app" class="dsk">
    <div class="dsk-boot" role="status">Loading Disembark&hellip;</div>
    <noscript><p>The Disembark dashboard needs JavaScript.</p></noscript>
</div>
