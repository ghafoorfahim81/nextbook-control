<!DOCTYPE html>
<html dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<body style="font-family: Tahoma, Arial, sans-serif; font-size: 15px; line-height: 1.8; color: #1e1b2e;">
    <div style="max-width: 560px; margin: 0 auto; padding: 16px; direction: {{ $rtl ? 'rtl' : 'ltr' }}; text-align: {{ $rtl ? 'right' : 'left' }};">
        {!! nl2br(e($body)) !!}
    </div>
</body>
</html>
