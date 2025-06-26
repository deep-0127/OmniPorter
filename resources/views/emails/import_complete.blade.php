<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Import Completed</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f8fa;
            color: #333;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 520px;
            margin: 40px auto;
            padding: 30px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
            text-align: center;
        }
        .status-box {
            display: inline-block;
            background-color: {{ $failedRows > 0 ? '#fff4e5' : '#eafbea' }};
            color: {{ $failedRows > 0 ? '#e67e22' : '#27ae60' }};
            font-size: 18px;
            font-weight: bold;
            padding: 10px 20px;
            border-radius: 10px;
            margin-top: 20px;
        }
        .footer {
            margin-top: 30px;
            font-size: 12px;
            color: #999;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>{{ $failedRows > 0 ? 'Import Completed with Errors' : 'Import Completed Successfully' }}</h2>

    <p>
        @if($failedRows > 0)
            The import process completed, but <strong>{{ $failedRows }}</strong> row{{ $failedRows > 1 ? 's were' : ' was' }} not imported due to errors.
            Please review the attached Excel file for details.
        @else
            Your data import was completed successfully.
            You can find row-wise results in the attached Excel file.
        @endif
    </p>

    <div class="status-box">
        {{ $failedRows > 0
            ? "File attached with error details"
            : "All rows imported successfully" }}
    </div>

    <p style="margin-top: 20px;">
        The attached file contains row-wise status, including both success and error details.
    </p>
    <p>If you have any questions, please contact your system administrator.</p>

    <div class="footer">
        <p>&mdash; HRMS Team</p>
    </div>
</div>
</body>
</html>
