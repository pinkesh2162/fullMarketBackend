<!DOCTYPE html>
<html>

<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            color: #333;
        }

        .container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .content {
            padding: 30px;
        }

        .title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 25px;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .info-table td {
            padding: 12px 0;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }

        .info-table td.label {
            width: 100px;
            font-weight: bold;
            color: #333;
            vertical-align: top;
        }

        .info-table td.value {
            color: #555;
            line-height: 1.5;
        }
    </style>
</head>

<body>
    <div class="container">
        <div style="background-color: #ffffff; text-align: center;">
            <img src="{{ asset('media/mail/header_logo.png') }}" alt="FullMarket" style="width: 100%; height: auto; display: block;">
        </div>
        <div class="content">
            <div class="title">New message from website contact form</div>
            <div class="subtitle">A visitor has sent a message from the FullMarket contact form.</div>

            <table class="info-table">
                <tr>
                    <td class="label">Name</td>
                    <td class="value">{{ $data['name'] }}</td>
                </tr>
                <tr>
                    <td class="label">Email</td>
                    <td class="value"><a href="mailto:{{ $data['email'] }}" style="color: #007bff; text-decoration: none;">{{ $data['email'] }}</a></td>
                </tr>
                <tr>
                    <td class="label">Subject</td>
                    <td class="value">{{ $data['subject'] }}</td>
                </tr>
                <tr>
                    <td class="label">Date</td>
                    <td class="value">{{ date('Y-m-d H:i:s') }}</td>
                </tr>
                <tr style="border-bottom:0px !important">
                    <td class="label">Message</td>
                    <td class="value">{{ $data['message'] }}</td>
                </tr>
            </table>
        </div>
        <div style="padding: 15px 35px; background-color: #fafafa;">
            <p style="color: #999; font-size: 12px; margin: 0;">© FullMarket</p>
        </div>
    </div>
</body>

</html>