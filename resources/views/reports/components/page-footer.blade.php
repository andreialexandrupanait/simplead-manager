{{-- Page footer — absolute-positioned inside each .page div (not on cover). --}}
<div class="page-footer">
    @if($companyLogo && file_exists($companyLogo))
        <img src="{{ $companyLogo }}" class="footer-logo" alt="">
    @endif
</div>
