@if($records->hasPages())
<nav class="form-actions" aria-label="{{ $label }} pagination">
    @if($records->previousPageUrl())<a class="button button--secondary button--small" href="{{ $records->previousPageUrl() }}">Previous</a>@endif
    <span aria-current="page">Page {{ $records->currentPage() }} of {{ $records->lastPage() }} &middot; {{ $records->total() }} results</span>
    @if($records->nextPageUrl())<a class="button button--secondary button--small" href="{{ $records->nextPageUrl() }}">Next</a>@endif
</nav>
@endif
