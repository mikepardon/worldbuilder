<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        EmailTemplate::firstOrCreate(['reference' => 'welcome'], [
            'display_name' => 'Welcome',
            'subject'      => 'Welcome to Worldbuilder, {{ name }}',
            'description'  => 'Sent immediately after a new account is created.',
            'mjml'         => $this->mjml_welcome,
            'html'         => $this->html_welcome,
        ]);

        EmailTemplate::firstOrCreate(['reference' => 'verify-email'], [
            'display_name' => 'Verify email address',
            'subject'      => 'Please verify your Worldbuilder email address',
            'description'  => 'Sent when a user needs to verify their email address.',
            'mjml'         => $this->mjml_verify_email,
            'html'         => $this->html_verify_email,
        ]);

        EmailTemplate::firstOrCreate(['reference' => 'reset-password'], [
            'display_name' => 'Reset password',
            'subject'      => 'Reset your Worldbuilder password',
            'description'  => 'Sent when a user requests a password reset.',
            'mjml'         => $this->mjml_reset_password,
            'html'         => $this->html_reset_password,
        ]);

        EmailTemplate::firstOrCreate(['reference' => 'world-invitation'], [
            'display_name' => 'World invitation',
            'subject'      => '{{ inviter_name }} has invited you to {{ world_name }}',
            'description'  => 'Sent when a GM invites someone to join their world as a player.',
            'mjml'         => $this->mjml_world_invitation,
            'html'         => $this->html_world_invitation,
        ]);

        EmailTemplate::firstOrCreate(['reference' => 'session-reminder'], [
            'display_name' => 'Session reminder',
            'subject'      => 'Session reminder — {{ session_title }} · {{ session_date }}',
            'description'  => 'Sent to players ahead of an upcoming session.',
            'mjml'         => $this->mjml_session_reminder,
            'html'         => $this->html_session_reminder,
        ]);

        EmailTemplate::firstOrCreate(['reference' => 'recap-published'], [
            'display_name' => 'Session recap published',
            'subject'      => 'Recap ready — {{ session_title }}',
            'description'  => 'Sent to players when the GM publishes a session recap.',
            'mjml'         => $this->mjml_recap_published,
            'html'         => $this->html_recap_published,
        ]);

        EmailTemplate::firstOrCreate(['reference' => 'new-subscriber'], [
            'display_name' => 'Newsletter welcome',
            'subject'      => 'Thanks for subscribing to Worldbuilder updates',
            'description'  => 'Sent to new newsletter subscribers.',
            'mjml'         => $this->mjml_new_subscriber,
            'html'         => $this->html_new_subscriber,
        ]);
    }

    private string $mjml_welcome = '<mjml>
  <mj-head>
    <mj-font name="Inter" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <mj-attributes>
      <mj-all font-family="Inter, -apple-system, BlinkMacSystemFont, sans-serif" />
      <mj-text font-size="15px" line-height="1.7" color="#374151" />
    </mj-attributes>
  </mj-head>
  <mj-body background-color="#f3f4f6">
  <mj-section background-color="#14161b" padding="24px 32px">
    <mj-column>
      <mj-text font-size="20px" font-weight="700" color="#f59e0b" letter-spacing="0.04em" font-family="Georgia, serif">
        Worldbuilder
      </mj-text>
    </mj-column>
  </mj-section>
    <mj-section background-color="#ffffff" padding="40px 32px 8px">
      <mj-column>
        <mj-text font-size="26px" font-weight="700" color="#111827" line-height="1.3" padding-bottom="16px">
          Your world awaits, {{ name }}.
        </mj-text>
        <mj-text padding-bottom="16px">
          Welcome to Worldbuilder — the collaborative platform for GMs and players to build, run, and remember their campaigns.
        </mj-text>
        <mj-text padding-bottom="24px">
          Get started by creating your first world, or accepting an invitation from your GM.
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="0 32px 40px">
      <mj-column>
      <mj-button background-color="#f59e0b" color="#000000" font-size="15px" font-weight="600" border-radius="6px" padding="14px 28px" font-family="Inter, -apple-system, sans-serif" href="https://worldbuilder.pro/dashboard">
        Go to your dashboard
      </mj-button>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="0 32px 40px" border-top="1px solid #f3f4f6">
      <mj-column>
        <mj-text font-size="13px" color="#6b7280">
          If you didn\'t create this account, you can safely ignore this email.
        </mj-text>
      </mj-column>
    </mj-section>
  <mj-section background-color="#f9fafb" padding="24px 32px" border-top="1px solid #e5e7eb">
    <mj-column>
      <mj-text font-size="12px" color="#9ca3af" align="center" font-family="Inter, -apple-system, sans-serif" line-height="1.6">
        &copy; 2026 Worldbuilder &middot; <a href="https://worldbuilder.pro" style="color:#9ca3af;text-decoration:none">worldbuilder.pro</a>
        <br />
        <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a>
      </mj-text>
    </mj-column>
  </mj-section>
  </mj-body>
</mjml>';

    private string $html_welcome = '<!doctype html>
<html lang="und" dir="auto" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
  <head>
    <title></title>
    <!--[if !mso]><!-->
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <!--<![endif]-->
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style type="text/css">
      #outlook a { padding:0; }
      body { margin:0;padding:0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%; }
      table, td { border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt; }
      img { border:0;height:auto;line-height:100%; outline:none;text-decoration:none;-ms-interpolation-mode:bicubic; }
      p { display:block;margin:13px 0; }
    </style>
    <!--[if mso]>
    <noscript>
    <xml>
    <o:OfficeDocumentSettings>
      <o:AllowPNG/>
      <o:PixelsPerInch>96</o:PixelsPerInch>
    </o:OfficeDocumentSettings>
    </xml>
    </noscript>
    <![endif]-->
    <!--[if lte mso 11]>
    <style type="text/css">
      .mj-outlook-group-fix { width:100% !important; }
    </style>
    <![endif]-->
    
      <!--[if !mso]><!-->
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" type="text/css">
        <style type="text/css">
          @import url(https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap);
        </style>
      <!--<![endif]-->

    
    
    <style type="text/css">
      @media only screen and (min-width:480px) {
        .mj-column-per-100 { width:100% !important; max-width: 100%; }
      }
    </style>
    <style media="screen and (min-width:480px)">
      .moz-text-html .mj-column-per-100 { width:100% !important; max-width: 100%; }
    </style>
    
    
  
    
    
    
  </head>
  
      <body  style="word-spacing:normal;background-color:#f3f4f6;">
        
        <div
           aria-roledescription="email" role="article" lang="und" dir="auto" style="word-spacing:normal;background-color:#f3f4f6;"
        >
        
      
      <!--[if mso | IE]><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#14161b" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#14161b;background-color:#14161b;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#14161b;background-color:#14161b;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:24px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Georgia, serif;font-size:20px;font-weight:700;letter-spacing:0.04em;line-height:1.7;text-align:left;color:#f59e0b;"
      >Worldbuilder</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:40px 32px 8px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:16px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:26px;font-weight:700;line-height:1.3;text-align:left;color:#111827;"
      >Your world awaits, {{ name }}.</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:16px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:15px;line-height:1.7;text-align:left;color:#374151;"
      >Welcome to Worldbuilder — the collaborative platform for GMs and players to build, run, and remember their campaigns.</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:24px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:15px;line-height:1.7;text-align:left;color:#374151;"
      >Get started by creating your first world, or accepting an invitation from your GM.</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:0 32px 40px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="center" style="font-size:0px;padding:14px 28px;word-break:break-word;"
                >
                  
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:separate;line-height:100%;"
      >
        <tbody>
          <tr>
            <td
               align="center" bgcolor="#f59e0b" role="presentation" style="border:none;border-radius:6px;cursor:auto;mso-padding-alt:10px 25px;background:#f59e0b;" valign="middle"
            >
              <a
                 href="https://worldbuilder.pro/dashboard" style="display:inline-block;background:#f59e0b;color:#000000;font-family:Inter, -apple-system, sans-serif;font-size:15px;font-weight:600;line-height:120%;margin:0;text-decoration:none;text-transform:none;padding:10px 25px;mso-padding-alt:0px;border-radius:6px;" target="_blank"
              >
                Go to your dashboard
              </a>
            </td>
          </tr>
        </tbody>
      </table>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="border-top:1px solid #f3f4f6;direction:ltr;font-size:0px;padding:0 32px 40px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:13px;line-height:1.7;text-align:left;color:#6b7280;"
      >If you didn\'t create this account, you can safely ignore this email.</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#f9fafb" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#f9fafb;background-color:#f9fafb;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#f9fafb;background-color:#f9fafb;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="border-top:1px solid #e5e7eb;direction:ltr;font-size:0px;padding:24px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="center" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, sans-serif;font-size:12px;line-height:1.6;text-align:center;color:#9ca3af;"
      >&copy; 2026 Worldbuilder &middot; <a href="https://worldbuilder.pro" style="color:#9ca3af;text-decoration:none">worldbuilder.pro</a>
        <br />
        <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a></div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><![endif]-->
    
    
      </div>
      </body>
    
</html>
  ';

    private string $mjml_verify_email = '<mjml>
  <mj-head>
    <mj-font name="Inter" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <mj-attributes>
      <mj-all font-family="Inter, -apple-system, BlinkMacSystemFont, sans-serif" />
      <mj-text font-size="15px" line-height="1.7" color="#374151" />
    </mj-attributes>
  </mj-head>
  <mj-body background-color="#f3f4f6">
  <mj-section background-color="#14161b" padding="24px 32px">
    <mj-column>
      <mj-text font-size="20px" font-weight="700" color="#f59e0b" letter-spacing="0.04em" font-family="Georgia, serif">
        Worldbuilder
      </mj-text>
    </mj-column>
  </mj-section>
    <mj-section background-color="#ffffff" padding="40px 32px 8px">
      <mj-column>
        <mj-text font-size="26px" font-weight="700" color="#111827" line-height="1.3" padding-bottom="16px">
          Verify your email address
        </mj-text>
        <mj-text padding-bottom="16px">
          Hi {{ name }}, thanks for signing up. Click the button below to confirm your email address and activate your account.
        </mj-text>
        <mj-text padding-bottom="24px" font-size="13px" color="#6b7280">
          This link expires in {{ expires_in }}.
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="0 32px 32px">
      <mj-column>
      <mj-button background-color="#f59e0b" color="#000000" font-size="15px" font-weight="600" border-radius="6px" padding="14px 28px" font-family="Inter, -apple-system, sans-serif" href="{{ verification_url }}">
        Verify email address
      </mj-button>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="0 32px 40px" border-top="1px solid #f3f4f6">
      <mj-column>
        <mj-text font-size="13px" color="#6b7280">
          Or copy and paste this URL into your browser:
          <a href="{{ verification_url }}" style="color:#6b7280;word-break:break-all">{{ verification_url }}</a>
        </mj-text>
        <mj-text font-size="13px" color="#6b7280" padding-top="12px">
          If you didn\'t create a Worldbuilder account, you can safely ignore this email.
        </mj-text>
      </mj-column>
    </mj-section>
  <mj-section background-color="#f9fafb" padding="24px 32px" border-top="1px solid #e5e7eb">
    <mj-column>
      <mj-text font-size="12px" color="#9ca3af" align="center" font-family="Inter, -apple-system, sans-serif" line-height="1.6">
        &copy; 2026 Worldbuilder &middot; <a href="https://worldbuilder.pro" style="color:#9ca3af;text-decoration:none">worldbuilder.pro</a>
        <br />
        <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a>
      </mj-text>
    </mj-column>
  </mj-section>
  </mj-body>
</mjml>';

    private string $html_verify_email = '<!doctype html>
<html lang="und" dir="auto" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
  <head>
    <title></title>
    <!--[if !mso]><!-->
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <!--<![endif]-->
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style type="text/css">
      #outlook a { padding:0; }
      body { margin:0;padding:0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%; }
      table, td { border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt; }
      img { border:0;height:auto;line-height:100%; outline:none;text-decoration:none;-ms-interpolation-mode:bicubic; }
      p { display:block;margin:13px 0; }
    </style>
    <!--[if mso]>
    <noscript>
    <xml>
    <o:OfficeDocumentSettings>
      <o:AllowPNG/>
      <o:PixelsPerInch>96</o:PixelsPerInch>
    </o:OfficeDocumentSettings>
    </xml>
    </noscript>
    <![endif]-->
    <!--[if lte mso 11]>
    <style type="text/css">
      .mj-outlook-group-fix { width:100% !important; }
    </style>
    <![endif]-->
    
      <!--[if !mso]><!-->
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" type="text/css">
        <style type="text/css">
          @import url(https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap);
        </style>
      <!--<![endif]-->

    
    
    <style type="text/css">
      @media only screen and (min-width:480px) {
        .mj-column-per-100 { width:100% !important; max-width: 100%; }
      }
    </style>
    <style media="screen and (min-width:480px)">
      .moz-text-html .mj-column-per-100 { width:100% !important; max-width: 100%; }
    </style>
    
    
  
    
    
    
  </head>
  
      <body  style="word-spacing:normal;background-color:#f3f4f6;">
        
        <div
           aria-roledescription="email" role="article" lang="und" dir="auto" style="word-spacing:normal;background-color:#f3f4f6;"
        >
        
      
      <!--[if mso | IE]><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#14161b" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#14161b;background-color:#14161b;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#14161b;background-color:#14161b;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:24px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Georgia, serif;font-size:20px;font-weight:700;letter-spacing:0.04em;line-height:1.7;text-align:left;color:#f59e0b;"
      >Worldbuilder</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:40px 32px 8px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:16px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:26px;font-weight:700;line-height:1.3;text-align:left;color:#111827;"
      >Verify your email address</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:16px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:15px;line-height:1.7;text-align:left;color:#374151;"
      >Hi {{ name }}, thanks for signing up. Click the button below to confirm your email address and activate your account.</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:24px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:13px;line-height:1.7;text-align:left;color:#6b7280;"
      >This link expires in {{ expires_in }}.</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:0 32px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="center" style="font-size:0px;padding:14px 28px;word-break:break-word;"
                >
                  
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:separate;line-height:100%;"
      >
        <tbody>
          <tr>
            <td
               align="center" bgcolor="#f59e0b" role="presentation" style="border:none;border-radius:6px;cursor:auto;mso-padding-alt:10px 25px;background:#f59e0b;" valign="middle"
            >
              <a
                 href="{{ verification_url }}" style="display:inline-block;background:#f59e0b;color:#000000;font-family:Inter, -apple-system, sans-serif;font-size:15px;font-weight:600;line-height:120%;margin:0;text-decoration:none;text-transform:none;padding:10px 25px;mso-padding-alt:0px;border-radius:6px;" target="_blank"
              >
                Verify email address
              </a>
            </td>
          </tr>
        </tbody>
      </table>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="border-top:1px solid #f3f4f6;direction:ltr;font-size:0px;padding:0 32px 40px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:13px;line-height:1.7;text-align:left;color:#6b7280;"
      >Or copy and paste this URL into your browser:
          <a href="{{ verification_url }}" style="color:#6b7280;word-break:break-all">{{ verification_url }}</a></div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-top:12px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:13px;line-height:1.7;text-align:left;color:#6b7280;"
      >If you didn\'t create a Worldbuilder account, you can safely ignore this email.</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#f9fafb" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#f9fafb;background-color:#f9fafb;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#f9fafb;background-color:#f9fafb;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="border-top:1px solid #e5e7eb;direction:ltr;font-size:0px;padding:24px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="center" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, sans-serif;font-size:12px;line-height:1.6;text-align:center;color:#9ca3af;"
      >&copy; 2026 Worldbuilder &middot; <a href="https://worldbuilder.pro" style="color:#9ca3af;text-decoration:none">worldbuilder.pro</a>
        <br />
        <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a></div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><![endif]-->
    
    
      </div>
      </body>
    
</html>
  ';

    private string $mjml_reset_password = '<mjml>
  <mj-head>
    <mj-font name="Inter" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <mj-attributes>
      <mj-all font-family="Inter, -apple-system, BlinkMacSystemFont, sans-serif" />
      <mj-text font-size="15px" line-height="1.7" color="#374151" />
    </mj-attributes>
  </mj-head>
  <mj-body background-color="#f3f4f6">
  <mj-section background-color="#14161b" padding="24px 32px">
    <mj-column>
      <mj-text font-size="20px" font-weight="700" color="#f59e0b" letter-spacing="0.04em" font-family="Georgia, serif">
        Worldbuilder
      </mj-text>
    </mj-column>
  </mj-section>
    <mj-section background-color="#ffffff" padding="40px 32px 8px">
      <mj-column>
        <mj-text font-size="26px" font-weight="700" color="#111827" line-height="1.3" padding-bottom="16px">
          Reset your password
        </mj-text>
        <mj-text padding-bottom="16px">
          Hi {{ name }}, we received a request to reset the password for your Worldbuilder account.
        </mj-text>
        <mj-text padding-bottom="24px" font-size="13px" color="#6b7280">
          This link expires in {{ expires_in }}. If you didn\'t request a reset, no action is needed — your password remains unchanged.
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="0 32px 32px">
      <mj-column>
      <mj-button background-color="#f59e0b" color="#000000" font-size="15px" font-weight="600" border-radius="6px" padding="14px 28px" font-family="Inter, -apple-system, sans-serif" href="{{ reset_url }}">
        Reset password
      </mj-button>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="0 32px 40px" border-top="1px solid #f3f4f6">
      <mj-column>
        <mj-text font-size="13px" color="#6b7280">
          Or copy and paste this URL into your browser:
          <a href="{{ reset_url }}" style="color:#6b7280;word-break:break-all">{{ reset_url }}</a>
        </mj-text>
      </mj-column>
    </mj-section>
  <mj-section background-color="#f9fafb" padding="24px 32px" border-top="1px solid #e5e7eb">
    <mj-column>
      <mj-text font-size="12px" color="#9ca3af" align="center" font-family="Inter, -apple-system, sans-serif" line-height="1.6">
        &copy; 2026 Worldbuilder &middot; <a href="https://worldbuilder.pro" style="color:#9ca3af;text-decoration:none">worldbuilder.pro</a>
        <br />
        <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a>
      </mj-text>
    </mj-column>
  </mj-section>
  </mj-body>
</mjml>';

    private string $html_reset_password = '<!doctype html>
<html lang="und" dir="auto" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
  <head>
    <title></title>
    <!--[if !mso]><!-->
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <!--<![endif]-->
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style type="text/css">
      #outlook a { padding:0; }
      body { margin:0;padding:0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%; }
      table, td { border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt; }
      img { border:0;height:auto;line-height:100%; outline:none;text-decoration:none;-ms-interpolation-mode:bicubic; }
      p { display:block;margin:13px 0; }
    </style>
    <!--[if mso]>
    <noscript>
    <xml>
    <o:OfficeDocumentSettings>
      <o:AllowPNG/>
      <o:PixelsPerInch>96</o:PixelsPerInch>
    </o:OfficeDocumentSettings>
    </xml>
    </noscript>
    <![endif]-->
    <!--[if lte mso 11]>
    <style type="text/css">
      .mj-outlook-group-fix { width:100% !important; }
    </style>
    <![endif]-->
    
      <!--[if !mso]><!-->
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" type="text/css">
        <style type="text/css">
          @import url(https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap);
        </style>
      <!--<![endif]-->

    
    
    <style type="text/css">
      @media only screen and (min-width:480px) {
        .mj-column-per-100 { width:100% !important; max-width: 100%; }
      }
    </style>
    <style media="screen and (min-width:480px)">
      .moz-text-html .mj-column-per-100 { width:100% !important; max-width: 100%; }
    </style>
    
    
  
    
    
    
  </head>
  
      <body  style="word-spacing:normal;background-color:#f3f4f6;">
        
        <div
           aria-roledescription="email" role="article" lang="und" dir="auto" style="word-spacing:normal;background-color:#f3f4f6;"
        >
        
      
      <!--[if mso | IE]><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#14161b" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#14161b;background-color:#14161b;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#14161b;background-color:#14161b;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:24px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Georgia, serif;font-size:20px;font-weight:700;letter-spacing:0.04em;line-height:1.7;text-align:left;color:#f59e0b;"
      >Worldbuilder</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:40px 32px 8px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:16px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:26px;font-weight:700;line-height:1.3;text-align:left;color:#111827;"
      >Reset your password</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:16px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:15px;line-height:1.7;text-align:left;color:#374151;"
      >Hi {{ name }}, we received a request to reset the password for your Worldbuilder account.</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:24px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:13px;line-height:1.7;text-align:left;color:#6b7280;"
      >This link expires in {{ expires_in }}. If you didn\'t request a reset, no action is needed — your password remains unchanged.</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:0 32px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="center" style="font-size:0px;padding:14px 28px;word-break:break-word;"
                >
                  
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:separate;line-height:100%;"
      >
        <tbody>
          <tr>
            <td
               align="center" bgcolor="#f59e0b" role="presentation" style="border:none;border-radius:6px;cursor:auto;mso-padding-alt:10px 25px;background:#f59e0b;" valign="middle"
            >
              <a
                 href="{{ reset_url }}" style="display:inline-block;background:#f59e0b;color:#000000;font-family:Inter, -apple-system, sans-serif;font-size:15px;font-weight:600;line-height:120%;margin:0;text-decoration:none;text-transform:none;padding:10px 25px;mso-padding-alt:0px;border-radius:6px;" target="_blank"
              >
                Reset password
              </a>
            </td>
          </tr>
        </tbody>
      </table>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="border-top:1px solid #f3f4f6;direction:ltr;font-size:0px;padding:0 32px 40px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:13px;line-height:1.7;text-align:left;color:#6b7280;"
      >Or copy and paste this URL into your browser:
          <a href="{{ reset_url }}" style="color:#6b7280;word-break:break-all">{{ reset_url }}</a></div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#f9fafb" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#f9fafb;background-color:#f9fafb;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#f9fafb;background-color:#f9fafb;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="border-top:1px solid #e5e7eb;direction:ltr;font-size:0px;padding:24px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="center" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, sans-serif;font-size:12px;line-height:1.6;text-align:center;color:#9ca3af;"
      >&copy; 2026 Worldbuilder &middot; <a href="https://worldbuilder.pro" style="color:#9ca3af;text-decoration:none">worldbuilder.pro</a>
        <br />
        <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a></div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><![endif]-->
    
    
      </div>
      </body>
    
</html>
  ';

    private string $mjml_world_invitation = '<mjml>
  <mj-head>
    <mj-font name="Inter" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <mj-attributes>
      <mj-all font-family="Inter, -apple-system, BlinkMacSystemFont, sans-serif" />
      <mj-text font-size="15px" line-height="1.7" color="#374151" />
    </mj-attributes>
  </mj-head>
  <mj-body background-color="#f3f4f6">
  <mj-section background-color="#14161b" padding="24px 32px">
    <mj-column>
      <mj-text font-size="20px" font-weight="700" color="#f59e0b" letter-spacing="0.04em" font-family="Georgia, serif">
        Worldbuilder
      </mj-text>
    </mj-column>
  </mj-section>
    <mj-section background-color="#1a1d24" padding="32px 32px 24px">
      <mj-column>
        <mj-text font-size="12px" font-weight="600" color="#f59e0b" letter-spacing="0.1em" text-transform="uppercase" padding-bottom="8px">
          You\'ve been invited
        </mj-text>
        <mj-text font-size="28px" font-weight="700" color="#f9fafb" line-height="1.25" padding-bottom="0">
          {{ world_name }}
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="32px 32px 8px">
      <mj-column>
        <mj-text padding-bottom="16px">
          Hi {{ name }}, <strong>{{ inviter_name }}</strong> has invited you to join their world on Worldbuilder.
        </mj-text>
        <mj-text padding-bottom="24px">
          Accept the invitation to access the world wiki, session schedule, and join your next game.
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="0 32px 40px">
      <mj-column>
      <mj-button background-color="#f59e0b" color="#000000" font-size="15px" font-weight="600" border-radius="6px" padding="14px 28px" font-family="Inter, -apple-system, sans-serif" href="{{ invitation_url }}">
        Accept invitation
      </mj-button>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="0 32px 40px" border-top="1px solid #f3f4f6">
      <mj-column>
        <mj-text font-size="13px" color="#6b7280">
          This invitation was sent by {{ inviter_name }}. If you weren\'t expecting it, you can safely ignore this email.
        </mj-text>
      </mj-column>
    </mj-section>
  <mj-section background-color="#f9fafb" padding="24px 32px" border-top="1px solid #e5e7eb">
    <mj-column>
      <mj-text font-size="12px" color="#9ca3af" align="center" font-family="Inter, -apple-system, sans-serif" line-height="1.6">
        &copy; 2026 Worldbuilder &middot; <a href="https://worldbuilder.pro" style="color:#9ca3af;text-decoration:none">worldbuilder.pro</a>
        <br />
        <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a>
      </mj-text>
    </mj-column>
  </mj-section>
  </mj-body>
</mjml>';

    private string $html_world_invitation = '<!doctype html>
<html lang="und" dir="auto" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
  <head>
    <title></title>
    <!--[if !mso]><!-->
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <!--<![endif]-->
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style type="text/css">
      #outlook a { padding:0; }
      body { margin:0;padding:0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%; }
      table, td { border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt; }
      img { border:0;height:auto;line-height:100%; outline:none;text-decoration:none;-ms-interpolation-mode:bicubic; }
      p { display:block;margin:13px 0; }
    </style>
    <!--[if mso]>
    <noscript>
    <xml>
    <o:OfficeDocumentSettings>
      <o:AllowPNG/>
      <o:PixelsPerInch>96</o:PixelsPerInch>
    </o:OfficeDocumentSettings>
    </xml>
    </noscript>
    <![endif]-->
    <!--[if lte mso 11]>
    <style type="text/css">
      .mj-outlook-group-fix { width:100% !important; }
    </style>
    <![endif]-->
    
      <!--[if !mso]><!-->
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" type="text/css">
        <style type="text/css">
          @import url(https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap);
        </style>
      <!--<![endif]-->

    
    
    <style type="text/css">
      @media only screen and (min-width:480px) {
        .mj-column-per-100 { width:100% !important; max-width: 100%; }
      }
    </style>
    <style media="screen and (min-width:480px)">
      .moz-text-html .mj-column-per-100 { width:100% !important; max-width: 100%; }
    </style>
    
    
  
    
    
    
  </head>
  
      <body  style="word-spacing:normal;background-color:#f3f4f6;">
        
        <div
           aria-roledescription="email" role="article" lang="und" dir="auto" style="word-spacing:normal;background-color:#f3f4f6;"
        >
        
      
      <!--[if mso | IE]><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#14161b" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#14161b;background-color:#14161b;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#14161b;background-color:#14161b;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:24px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Georgia, serif;font-size:20px;font-weight:700;letter-spacing:0.04em;line-height:1.7;text-align:left;color:#f59e0b;"
      >Worldbuilder</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#1a1d24" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#1a1d24;background-color:#1a1d24;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#1a1d24;background-color:#1a1d24;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:32px 32px 24px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:8px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:12px;font-weight:600;letter-spacing:0.1em;line-height:1.7;text-align:left;text-transform:uppercase;color:#f59e0b;"
      >You\'ve been invited</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:0;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:28px;font-weight:700;line-height:1.25;text-align:left;color:#f9fafb;"
      >{{ world_name }}</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:32px 32px 8px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:16px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:15px;line-height:1.7;text-align:left;color:#374151;"
      >Hi {{ name }}, <strong>{{ inviter_name }}</strong> has invited you to join their world on Worldbuilder.</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:24px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:15px;line-height:1.7;text-align:left;color:#374151;"
      >Accept the invitation to access the world wiki, session schedule, and join your next game.</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:0 32px 40px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="center" style="font-size:0px;padding:14px 28px;word-break:break-word;"
                >
                  
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:separate;line-height:100%;"
      >
        <tbody>
          <tr>
            <td
               align="center" bgcolor="#f59e0b" role="presentation" style="border:none;border-radius:6px;cursor:auto;mso-padding-alt:10px 25px;background:#f59e0b;" valign="middle"
            >
              <a
                 href="{{ invitation_url }}" style="display:inline-block;background:#f59e0b;color:#000000;font-family:Inter, -apple-system, sans-serif;font-size:15px;font-weight:600;line-height:120%;margin:0;text-decoration:none;text-transform:none;padding:10px 25px;mso-padding-alt:0px;border-radius:6px;" target="_blank"
              >
                Accept invitation
              </a>
            </td>
          </tr>
        </tbody>
      </table>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="border-top:1px solid #f3f4f6;direction:ltr;font-size:0px;padding:0 32px 40px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:13px;line-height:1.7;text-align:left;color:#6b7280;"
      >This invitation was sent by {{ inviter_name }}. If you weren\'t expecting it, you can safely ignore this email.</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#f9fafb" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#f9fafb;background-color:#f9fafb;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#f9fafb;background-color:#f9fafb;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="border-top:1px solid #e5e7eb;direction:ltr;font-size:0px;padding:24px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="center" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, sans-serif;font-size:12px;line-height:1.6;text-align:center;color:#9ca3af;"
      >&copy; 2026 Worldbuilder &middot; <a href="https://worldbuilder.pro" style="color:#9ca3af;text-decoration:none">worldbuilder.pro</a>
        <br />
        <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a></div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><![endif]-->
    
    
      </div>
      </body>
    
</html>
  ';

    private string $mjml_session_reminder = '<mjml>
  <mj-head>
    <mj-font name="Inter" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <mj-attributes>
      <mj-all font-family="Inter, -apple-system, BlinkMacSystemFont, sans-serif" />
      <mj-text font-size="15px" line-height="1.7" color="#374151" />
    </mj-attributes>
  </mj-head>
  <mj-body background-color="#f3f4f6">
  <mj-section background-color="#14161b" padding="24px 32px">
    <mj-column>
      <mj-text font-size="20px" font-weight="700" color="#f59e0b" letter-spacing="0.04em" font-family="Georgia, serif">
        Worldbuilder
      </mj-text>
    </mj-column>
  </mj-section>
    <mj-section background-color="#1a1d24" padding="32px 32px 24px">
      <mj-column>
        <mj-text font-size="12px" font-weight="600" color="#f59e0b" letter-spacing="0.1em" text-transform="uppercase" padding-bottom="8px">
          {{ world_name }}
        </mj-text>
        <mj-text font-size="26px" font-weight="700" color="#f9fafb" line-height="1.3" padding-bottom="8px">
          {{ session_title }}
        </mj-text>
        <mj-text font-size="15px" color="#9ca3af" padding-bottom="0">
          {{ session_date }} &middot; {{ session_time }}
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="32px 32px 8px">
      <mj-column>
        <mj-text padding-bottom="16px">
          Hi {{ name }}, just a reminder that your next session is coming up.
        </mj-text>
        <mj-text padding-bottom="24px" font-size="14px" color="#6b7280">
          Review your notes or browse the world wiki to get back up to speed before the session.
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="0 32px 40px">
      <mj-column>
      <mj-button background-color="#f59e0b" color="#000000" font-size="15px" font-weight="600" border-radius="6px" padding="14px 28px" font-family="Inter, -apple-system, sans-serif" href="{{ session_url }}">
        View session
      </mj-button>
      </mj-column>
    </mj-section>
  <mj-section background-color="#f9fafb" padding="24px 32px" border-top="1px solid #e5e7eb">
    <mj-column>
      <mj-text font-size="12px" color="#9ca3af" align="center" font-family="Inter, -apple-system, sans-serif" line-height="1.6">
        &copy; 2026 Worldbuilder &middot; <a href="https://worldbuilder.pro" style="color:#9ca3af;text-decoration:none">worldbuilder.pro</a>
        <br />
        <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a>
      </mj-text>
    </mj-column>
  </mj-section>
  </mj-body>
</mjml>';

    private string $html_session_reminder = '<!doctype html>
<html lang="und" dir="auto" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
  <head>
    <title></title>
    <!--[if !mso]><!-->
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <!--<![endif]-->
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style type="text/css">
      #outlook a { padding:0; }
      body { margin:0;padding:0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%; }
      table, td { border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt; }
      img { border:0;height:auto;line-height:100%; outline:none;text-decoration:none;-ms-interpolation-mode:bicubic; }
      p { display:block;margin:13px 0; }
    </style>
    <!--[if mso]>
    <noscript>
    <xml>
    <o:OfficeDocumentSettings>
      <o:AllowPNG/>
      <o:PixelsPerInch>96</o:PixelsPerInch>
    </o:OfficeDocumentSettings>
    </xml>
    </noscript>
    <![endif]-->
    <!--[if lte mso 11]>
    <style type="text/css">
      .mj-outlook-group-fix { width:100% !important; }
    </style>
    <![endif]-->
    
      <!--[if !mso]><!-->
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" type="text/css">
        <style type="text/css">
          @import url(https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap);
        </style>
      <!--<![endif]-->

    
    
    <style type="text/css">
      @media only screen and (min-width:480px) {
        .mj-column-per-100 { width:100% !important; max-width: 100%; }
      }
    </style>
    <style media="screen and (min-width:480px)">
      .moz-text-html .mj-column-per-100 { width:100% !important; max-width: 100%; }
    </style>
    
    
  
    
    
    
  </head>
  
      <body  style="word-spacing:normal;background-color:#f3f4f6;">
        
        <div
           aria-roledescription="email" role="article" lang="und" dir="auto" style="word-spacing:normal;background-color:#f3f4f6;"
        >
        
      
      <!--[if mso | IE]><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#14161b" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#14161b;background-color:#14161b;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#14161b;background-color:#14161b;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:24px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Georgia, serif;font-size:20px;font-weight:700;letter-spacing:0.04em;line-height:1.7;text-align:left;color:#f59e0b;"
      >Worldbuilder</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#1a1d24" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#1a1d24;background-color:#1a1d24;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#1a1d24;background-color:#1a1d24;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:32px 32px 24px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:8px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:12px;font-weight:600;letter-spacing:0.1em;line-height:1.7;text-align:left;text-transform:uppercase;color:#f59e0b;"
      >{{ world_name }}</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:8px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:26px;font-weight:700;line-height:1.3;text-align:left;color:#f9fafb;"
      >{{ session_title }}</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:0;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:15px;line-height:1.7;text-align:left;color:#9ca3af;"
      >{{ session_date }} &middot; {{ session_time }}</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:32px 32px 8px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:16px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:15px;line-height:1.7;text-align:left;color:#374151;"
      >Hi {{ name }}, just a reminder that your next session is coming up.</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:24px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:14px;line-height:1.7;text-align:left;color:#6b7280;"
      >Review your notes or browse the world wiki to get back up to speed before the session.</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:0 32px 40px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="center" style="font-size:0px;padding:14px 28px;word-break:break-word;"
                >
                  
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:separate;line-height:100%;"
      >
        <tbody>
          <tr>
            <td
               align="center" bgcolor="#f59e0b" role="presentation" style="border:none;border-radius:6px;cursor:auto;mso-padding-alt:10px 25px;background:#f59e0b;" valign="middle"
            >
              <a
                 href="{{ session_url }}" style="display:inline-block;background:#f59e0b;color:#000000;font-family:Inter, -apple-system, sans-serif;font-size:15px;font-weight:600;line-height:120%;margin:0;text-decoration:none;text-transform:none;padding:10px 25px;mso-padding-alt:0px;border-radius:6px;" target="_blank"
              >
                View session
              </a>
            </td>
          </tr>
        </tbody>
      </table>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#f9fafb" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#f9fafb;background-color:#f9fafb;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#f9fafb;background-color:#f9fafb;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="border-top:1px solid #e5e7eb;direction:ltr;font-size:0px;padding:24px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="center" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, sans-serif;font-size:12px;line-height:1.6;text-align:center;color:#9ca3af;"
      >&copy; 2026 Worldbuilder &middot; <a href="https://worldbuilder.pro" style="color:#9ca3af;text-decoration:none">worldbuilder.pro</a>
        <br />
        <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a></div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><![endif]-->
    
    
      </div>
      </body>
    
</html>
  ';

    private string $mjml_recap_published = '<mjml>
  <mj-head>
    <mj-font name="Inter" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <mj-attributes>
      <mj-all font-family="Inter, -apple-system, BlinkMacSystemFont, sans-serif" />
      <mj-text font-size="15px" line-height="1.7" color="#374151" />
    </mj-attributes>
  </mj-head>
  <mj-body background-color="#f3f4f6">
  <mj-section background-color="#14161b" padding="24px 32px">
    <mj-column>
      <mj-text font-size="20px" font-weight="700" color="#f59e0b" letter-spacing="0.04em" font-family="Georgia, serif">
        Worldbuilder
      </mj-text>
    </mj-column>
  </mj-section>
    <mj-section background-color="#1a1d24" padding="32px 32px 24px">
      <mj-column>
        <mj-text font-size="12px" font-weight="600" color="#f59e0b" letter-spacing="0.1em" text-transform="uppercase" padding-bottom="8px">
          {{ world_name }}
        </mj-text>
        <mj-text font-size="26px" font-weight="700" color="#f9fafb" line-height="1.3" padding-bottom="8px">
          {{ session_title }}
        </mj-text>
        <mj-text font-size="15px" color="#9ca3af" padding-bottom="0">
          Session recap
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="32px 32px 8px">
      <mj-column>
        <mj-text padding-bottom="16px">
          Hi {{ name }}, your GM has published the recap for <strong>{{ session_title }}</strong>.
        </mj-text>
        <mj-text padding-bottom="24px" font-size="14px" color="#6b7280">
          {{ recap_summary }}
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="0 32px 40px">
      <mj-column>
      <mj-button background-color="#f59e0b" color="#000000" font-size="15px" font-weight="600" border-radius="6px" padding="14px 28px" font-family="Inter, -apple-system, sans-serif" href="{{ recap_url }}">
        Read the recap
      </mj-button>
      </mj-column>
    </mj-section>
  <mj-section background-color="#f9fafb" padding="24px 32px" border-top="1px solid #e5e7eb">
    <mj-column>
      <mj-text font-size="12px" color="#9ca3af" align="center" font-family="Inter, -apple-system, sans-serif" line-height="1.6">
        &copy; 2026 Worldbuilder &middot; <a href="https://worldbuilder.pro" style="color:#9ca3af;text-decoration:none">worldbuilder.pro</a>
        <br />
        <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a>
      </mj-text>
    </mj-column>
  </mj-section>
  </mj-body>
</mjml>';

    private string $html_recap_published = '<!doctype html>
<html lang="und" dir="auto" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
  <head>
    <title></title>
    <!--[if !mso]><!-->
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <!--<![endif]-->
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style type="text/css">
      #outlook a { padding:0; }
      body { margin:0;padding:0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%; }
      table, td { border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt; }
      img { border:0;height:auto;line-height:100%; outline:none;text-decoration:none;-ms-interpolation-mode:bicubic; }
      p { display:block;margin:13px 0; }
    </style>
    <!--[if mso]>
    <noscript>
    <xml>
    <o:OfficeDocumentSettings>
      <o:AllowPNG/>
      <o:PixelsPerInch>96</o:PixelsPerInch>
    </o:OfficeDocumentSettings>
    </xml>
    </noscript>
    <![endif]-->
    <!--[if lte mso 11]>
    <style type="text/css">
      .mj-outlook-group-fix { width:100% !important; }
    </style>
    <![endif]-->
    
      <!--[if !mso]><!-->
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" type="text/css">
        <style type="text/css">
          @import url(https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap);
        </style>
      <!--<![endif]-->

    
    
    <style type="text/css">
      @media only screen and (min-width:480px) {
        .mj-column-per-100 { width:100% !important; max-width: 100%; }
      }
    </style>
    <style media="screen and (min-width:480px)">
      .moz-text-html .mj-column-per-100 { width:100% !important; max-width: 100%; }
    </style>
    
    
  
    
    
    
  </head>
  
      <body  style="word-spacing:normal;background-color:#f3f4f6;">
        
        <div
           aria-roledescription="email" role="article" lang="und" dir="auto" style="word-spacing:normal;background-color:#f3f4f6;"
        >
        
      
      <!--[if mso | IE]><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#14161b" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#14161b;background-color:#14161b;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#14161b;background-color:#14161b;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:24px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Georgia, serif;font-size:20px;font-weight:700;letter-spacing:0.04em;line-height:1.7;text-align:left;color:#f59e0b;"
      >Worldbuilder</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#1a1d24" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#1a1d24;background-color:#1a1d24;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#1a1d24;background-color:#1a1d24;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:32px 32px 24px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:8px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:12px;font-weight:600;letter-spacing:0.1em;line-height:1.7;text-align:left;text-transform:uppercase;color:#f59e0b;"
      >{{ world_name }}</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:8px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:26px;font-weight:700;line-height:1.3;text-align:left;color:#f9fafb;"
      >{{ session_title }}</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:0;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:15px;line-height:1.7;text-align:left;color:#9ca3af;"
      >Session recap</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:32px 32px 8px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:16px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:15px;line-height:1.7;text-align:left;color:#374151;"
      >Hi {{ name }}, your GM has published the recap for <strong>{{ session_title }}</strong>.</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:24px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:14px;line-height:1.7;text-align:left;color:#6b7280;"
      >{{ recap_summary }}</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:0 32px 40px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="center" style="font-size:0px;padding:14px 28px;word-break:break-word;"
                >
                  
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:separate;line-height:100%;"
      >
        <tbody>
          <tr>
            <td
               align="center" bgcolor="#f59e0b" role="presentation" style="border:none;border-radius:6px;cursor:auto;mso-padding-alt:10px 25px;background:#f59e0b;" valign="middle"
            >
              <a
                 href="{{ recap_url }}" style="display:inline-block;background:#f59e0b;color:#000000;font-family:Inter, -apple-system, sans-serif;font-size:15px;font-weight:600;line-height:120%;margin:0;text-decoration:none;text-transform:none;padding:10px 25px;mso-padding-alt:0px;border-radius:6px;" target="_blank"
              >
                Read the recap
              </a>
            </td>
          </tr>
        </tbody>
      </table>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#f9fafb" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#f9fafb;background-color:#f9fafb;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#f9fafb;background-color:#f9fafb;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="border-top:1px solid #e5e7eb;direction:ltr;font-size:0px;padding:24px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="center" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, sans-serif;font-size:12px;line-height:1.6;text-align:center;color:#9ca3af;"
      >&copy; 2026 Worldbuilder &middot; <a href="https://worldbuilder.pro" style="color:#9ca3af;text-decoration:none">worldbuilder.pro</a>
        <br />
        <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a></div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><![endif]-->
    
    
      </div>
      </body>
    
</html>
  ';

    private string $mjml_new_subscriber = '<mjml>
  <mj-head>
    <mj-font name="Inter" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <mj-attributes>
      <mj-all font-family="Inter, -apple-system, BlinkMacSystemFont, sans-serif" />
      <mj-text font-size="15px" line-height="1.7" color="#374151" />
    </mj-attributes>
  </mj-head>
  <mj-body background-color="#f3f4f6">
  <mj-section background-color="#14161b" padding="24px 32px">
    <mj-column>
      <mj-text font-size="20px" font-weight="700" color="#f59e0b" letter-spacing="0.04em" font-family="Georgia, serif">
        Worldbuilder
      </mj-text>
    </mj-column>
  </mj-section>
    <mj-section background-color="#ffffff" padding="40px 32px 8px">
      <mj-column>
        <mj-text font-size="26px" font-weight="700" color="#111827" line-height="1.3" padding-bottom="16px">
          You\'re on the list, {{ name }}.
        </mj-text>
        <mj-text padding-bottom="16px">
          Thanks for subscribing to Worldbuilder updates. We\'ll send you news about new features, tips for running better campaigns, and the occasional deep-dive into the craft of collaborative storytelling.
        </mj-text>
        <mj-text padding-bottom="24px">
          In the meantime, if you haven\'t already &mdash; create your free account and start building.
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section background-color="#ffffff" padding="0 32px 40px">
      <mj-column>
      <mj-button background-color="#f59e0b" color="#000000" font-size="15px" font-weight="600" border-radius="6px" padding="14px 28px" font-family="Inter, -apple-system, sans-serif" href="https://worldbuilder.pro/register">
        Get started free
      </mj-button>
      </mj-column>
    </mj-section>
  <mj-section background-color="#f9fafb" padding="24px 32px" border-top="1px solid #e5e7eb">
    <mj-column>
      <mj-text font-size="12px" color="#9ca3af" align="center" font-family="Inter, -apple-system, sans-serif" line-height="1.6">
        &copy; 2026 Worldbuilder &middot; <a href="https://worldbuilder.pro" style="color:#9ca3af;text-decoration:none">worldbuilder.pro</a>
        <br />
        <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a>
      </mj-text>
    </mj-column>
  </mj-section>
  </mj-body>
</mjml>';

    private string $html_new_subscriber = '<!doctype html>
<html lang="und" dir="auto" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
  <head>
    <title></title>
    <!--[if !mso]><!-->
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <!--<![endif]-->
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style type="text/css">
      #outlook a { padding:0; }
      body { margin:0;padding:0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%; }
      table, td { border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt; }
      img { border:0;height:auto;line-height:100%; outline:none;text-decoration:none;-ms-interpolation-mode:bicubic; }
      p { display:block;margin:13px 0; }
    </style>
    <!--[if mso]>
    <noscript>
    <xml>
    <o:OfficeDocumentSettings>
      <o:AllowPNG/>
      <o:PixelsPerInch>96</o:PixelsPerInch>
    </o:OfficeDocumentSettings>
    </xml>
    </noscript>
    <![endif]-->
    <!--[if lte mso 11]>
    <style type="text/css">
      .mj-outlook-group-fix { width:100% !important; }
    </style>
    <![endif]-->
    
      <!--[if !mso]><!-->
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" type="text/css">
        <style type="text/css">
          @import url(https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap);
        </style>
      <!--<![endif]-->

    
    
    <style type="text/css">
      @media only screen and (min-width:480px) {
        .mj-column-per-100 { width:100% !important; max-width: 100%; }
      }
    </style>
    <style media="screen and (min-width:480px)">
      .moz-text-html .mj-column-per-100 { width:100% !important; max-width: 100%; }
    </style>
    
    
  
    
    
    
  </head>
  
      <body  style="word-spacing:normal;background-color:#f3f4f6;">
        
        <div
           aria-roledescription="email" role="article" lang="und" dir="auto" style="word-spacing:normal;background-color:#f3f4f6;"
        >
        
      
      <!--[if mso | IE]><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#14161b" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#14161b;background-color:#14161b;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#14161b;background-color:#14161b;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:24px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Georgia, serif;font-size:20px;font-weight:700;letter-spacing:0.04em;line-height:1.7;text-align:left;color:#f59e0b;"
      >Worldbuilder</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:40px 32px 8px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:16px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:26px;font-weight:700;line-height:1.3;text-align:left;color:#111827;"
      >You\'re on the list, {{ name }}.</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:16px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:15px;line-height:1.7;text-align:left;color:#374151;"
      >Thanks for subscribing to Worldbuilder updates. We\'ll send you news about new features, tips for running better campaigns, and the occasional deep-dive into the craft of collaborative storytelling.</div>
    
                </td>
              </tr>
            
              <tr>
                <td
                   align="left" style="font-size:0px;padding:10px 25px;padding-bottom:24px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, BlinkMacSystemFont, sans-serif;font-size:15px;line-height:1.7;text-align:left;color:#374151;"
      >In the meantime, if you haven\'t already &mdash; create your free account and start building.</div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#ffffff" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#ffffff;background-color:#ffffff;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;background-color:#ffffff;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="direction:ltr;font-size:0px;padding:0 32px 40px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="center" style="font-size:0px;padding:14px 28px;word-break:break-word;"
                >
                  
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:separate;line-height:100%;"
      >
        <tbody>
          <tr>
            <td
               align="center" bgcolor="#f59e0b" role="presentation" style="border:none;border-radius:6px;cursor:auto;mso-padding-alt:10px 25px;background:#f59e0b;" valign="middle"
            >
              <a
                 href="https://worldbuilder.pro/register" style="display:inline-block;background:#f59e0b;color:#000000;font-family:Inter, -apple-system, sans-serif;font-size:15px;font-weight:600;line-height:120%;margin:0;text-decoration:none;text-transform:none;padding:10px 25px;mso-padding-alt:0px;border-radius:6px;" target="_blank"
              >
                Get started free
              </a>
            </td>
          </tr>
        </tbody>
      </table>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><table align="center" border="0" cellpadding="0" cellspacing="0" class="" role="presentation" style="width:600px;" width="600" bgcolor="#f9fafb" ><tr><td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;"><![endif]-->
    
      
      <div  style="background:#f9fafb;background-color:#f9fafb;margin:0px auto;max-width:600px;">
        
        <table
           align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:#f9fafb;background-color:#f9fafb;width:100%;"
        >
          <tbody>
            <tr>
              <td
                 style="border-top:1px solid #e5e7eb;direction:ltr;font-size:0px;padding:24px 32px;text-align:center;"
              >
                <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td class="" style="vertical-align:top;width:536px;" ><![endif]-->
            
      <div
         class="mj-column-per-100 mj-outlook-group-fix" style="font-size:0px;text-align:left;direction:ltr;display:inline-block;vertical-align:top;width:100%;"
      >
        
      <table
         border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align:top;" width="100%"
      >
        <tbody>
          
              <tr>
                <td
                   align="center" style="font-size:0px;padding:10px 25px;word-break:break-word;"
                >
                  
      <div
         style="font-family:Inter, -apple-system, sans-serif;font-size:12px;line-height:1.6;text-align:center;color:#9ca3af;"
      >&copy; 2026 Worldbuilder &middot; <a href="https://worldbuilder.pro" style="color:#9ca3af;text-decoration:none">worldbuilder.pro</a>
        <br />
        <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a></div>
    
                </td>
              </tr>
            
        </tbody>
      </table>
    
      </div>
    
          <!--[if mso | IE]></td></tr></table><![endif]-->
              </td>
            </tr>
          </tbody>
        </table>
        
      </div>
    
      
      <!--[if mso | IE]></td></tr></table><![endif]-->
    
    
      </div>
      </body>
    
</html>
  ';
}
