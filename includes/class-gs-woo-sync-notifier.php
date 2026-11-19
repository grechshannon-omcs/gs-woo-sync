<?php
defined( 'ABSPATH' ) || exit;

class GS_Woo_Sync_Notifier {

    public static function maybe_send( array $results, string $trigger ): void {
        $opts = GS_Woo_Sync_Settings::get();

        $notify_when = $opts['notify_when'] ?? 'off';
        if ( $notify_when === 'off' ) return;

        $recipient = trim( $opts['notify_email'] ?? '' );
        if ( ! $recipient ) {
            $recipient = get_option( 'admin_email' );
        }

        // Determine whether to send based on setting
        $has_errors  = $results['errors']  > 0;
        $has_trashed = $results['trashed'] > 0;
        $has_changes = $results['created'] > 0 || $results['updated'] > 0 || $has_trashed;

        $should_send = match ( $notify_when ) {
            'always'        => true,
            'changes'       => $has_changes,
            'errors'        => $has_errors,
            'errors_trash'  => $has_errors || $has_trashed,
            default         => false,
        };

        if ( ! $should_send ) return;

        self::send( $recipient, $results, $trigger );
    }

    private static function send( string $to, array $results, string $trigger ): void {
        $site    = get_bloginfo( 'name' );
        $subject = sprintf( '[%s] GS – Woo Sync: %s run completed', $site, ucfirst( $trigger ) );

        $body = self::build_html( $results, $trigger, $site );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site . ' <' . get_option( 'admin_email' ) . '>',
        ];

        wp_mail( $to, $subject, $body, $headers );
    }

    private static function build_html( array $results, string $trigger, string $site ): string {
        $time     = current_time( 'Y-m-d H:i:s' );
        $created  = (int) $results['created'];
        $updated  = (int) $results['updated'];
        $skipped  = (int) $results['skipped'];
        $trashed  = (int) $results['trashed'];
        $errors   = (int) $results['errors'];
        $duration = round( $results['duration'] ?? 0, 2 );

        $pill = fn( $color, $label, $count ) => "
            <td style='padding:8px 16px;text-align:center;'>
                <div style='background:{$color};border-radius:8px;padding:6px 14px;display:inline-block;'>
                    <span style='font-size:20px;font-weight:700;color:#fff;'>{$count}</span><br>
                    <span style='font-size:11px;color:rgba(255,255,255,0.85);text-transform:uppercase;letter-spacing:.5px;'>{$label}</span>
                </div>
            </td>";

        $item_rows = fn( array $items, string $color ) => implode( '', array_map(
            fn( $i ) => "<tr>
                <td style='padding:5px 12px;border-bottom:1px solid #f0f0f0;'><code style='background:#f6f7f7;padding:2px 6px;border-radius:3px;font-size:12px;'>{$i['sku']}</code></td>
                <td style='padding:5px 12px;border-bottom:1px solid #f0f0f0;color:#333;'>{$i['name']}</td>
            </tr>",
            $items
        ) );

        $section = fn( string $title, string $color, array $items ) => empty( $items ) ? '' : "
            <div style='margin:20px 0;border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;'>
                <div style='background:{$color};padding:8px 14px;'>
                    <strong style='color:#fff;font-size:13px;'>{$title}</strong>
                </div>
                <table style='width:100%;border-collapse:collapse;'>
                    <thead><tr>
                        <th style='padding:6px 12px;background:#fafafa;text-align:left;font-size:12px;border-bottom:1px solid #e0e0e0;'>SKU</th>
                        <th style='padding:6px 12px;background:#fafafa;text-align:left;font-size:12px;border-bottom:1px solid #e0e0e0;'>Product</th>
                    </tr></thead>
                    <tbody>" . $item_rows( $items, $color ) . "</tbody>
                </table>
            </div>";

        $details = '';
        if ( $created > 0 ) $details .= $section( "✅ Created ({$created})", '#2e7d32', $results['created_items'] ?? [] );
        if ( $updated > 0 ) $details .= $section( "🔄 Updated ({$updated})", '#1565c0', $results['updated_items'] ?? [] );
        if ( $trashed > 0 ) $details .= $section( "🗑 Trashed ({$trashed})", '#e65100', $results['trashed_items'] ?? [] );
        if ( $errors  > 0 ) {
            $error_items = array_map( fn($n) => ['sku' => '—', 'name' => $n], $results['notes'] ?? [] );
            $details    .= $section( "❌ Errors ({$errors})", '#c62828', $error_items );
        }

        if ( ! $details ) {
            $details = '<p style="color:#888;font-style:italic;text-align:center;padding:16px 0;">No changes — all products were already up to date.</p>';
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f5f5f5;">
<div style="max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

  <!-- Header -->
  <div style="background:#2271b1;padding:20px 24px;">
    <h1 style="margin:0;color:#fff;font-size:18px;font-weight:700;">🔄 GS – Woo Sync Report</h1>
    <p style="margin:4px 0 0;color:rgba(255,255,255,0.8);font-size:13px;">{$site} &nbsp;·&nbsp; {$time} &nbsp;·&nbsp; triggered by <strong>{$trigger}</strong></p>
  </div>

  <!-- Summary pills -->
  <div style="padding:20px 24px 0;">
    <table style="width:100%;border-collapse:collapse;">
      <tr>
        {$pill('#2e7d32', 'Created', $created)}
        {$pill('#1565c0', 'Updated', $updated)}
        {$pill('#757575', 'Skipped', $skipped)}
        {$pill('#e65100', 'Trashed', $trashed)}
        {$pill('#c62828', 'Errors',  $errors)}
      </tr>
    </table>
    <p style="text-align:right;color:#aaa;font-size:11px;margin:8px 0 0;">Duration: {$duration}s</p>
  </div>

  <!-- Detail sections -->
  <div style="padding:0 24px 24px;">
    {$details}
  </div>

  <!-- Footer -->
  <div style="background:#f6f7f7;padding:12px 24px;border-top:1px solid #e0e0e0;text-align:center;">
    <p style="margin:0;color:#aaa;font-size:11px;">Sent by GS &ndash; Woo Sync v' . GS_WOO_SYNC_VERSION . ' &nbsp;&middot;&nbsp; <a href="' . admin_url('admin.php?page=gs-woo-sync') . '" style="color:#2271b1;">Open Plugin</a></p>
  </div>

</div>
</body>
</html>
HTML;
    }
}
