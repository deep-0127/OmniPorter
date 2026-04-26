<!DOCTYPE html>
<html>
<head>
    <title>Import Complete</title>
</head>
<body>
    <h1>Import Complete</h1>
    <p>Your import has been processed.</p>
    @if($failedRows > 0)
        <p><strong>{{ $failedRows }} rows failed.</strong></p>
    @else
        <p>All rows were imported successfully.</p>
    @endif
    <p>Results are attached.</p>
</body>
</html>
