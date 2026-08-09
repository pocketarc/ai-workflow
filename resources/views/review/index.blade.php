@php
    use AiWorkflow\Eval\StructuredResponsePresenter;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Review AI decisions</title>
<style>
  :root { --ink:#16191d; --muted:#5c6470; --line:#e3e6ea; --panel:#f7f8fa; --good:#1a7f4b; --bad:#b4232c; --accent:#2b5fd9; }
  * { box-sizing:border-box; }
  body { margin:0; padding:2rem 1.5rem 5rem; color:var(--ink); background:#fff;
         font:15px/1.55 ui-sans-serif,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
  .wrap { max-width:1000px; margin:0 auto; }
  h1 { font-size:1.5rem; margin:0 0 .25rem; letter-spacing:-.01em; }
  .sub { color:var(--muted); font-size:.9rem; margin:0 0 1.25rem; }
  .filters { background:var(--panel); border:1px solid var(--line); border-radius:8px; padding:.75rem .9rem; margin-bottom:1.5rem;
             display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; font-size:.88rem; }
  .filters input[type=text] { padding:.35rem .5rem; border:1px solid var(--line); border-radius:5px; font:inherit; }
  .status { background:#e7f6ed; border:1px solid #9ed7b6; color:#14562f; border-radius:6px; padding:.6rem .8rem; margin-bottom:1rem; font-size:.88rem; }
  .card { border:1px solid var(--line); border-radius:8px; padding:1rem 1.1rem; margin-bottom:1.25rem; }
  .card h2 { font-size:.95rem; margin:0 0 .1rem; }
  .meta { color:var(--muted); font-size:.8rem; margin-bottom:.75rem; }
  .answered { color:var(--good); font-weight:600; }
  .unanswered { color:var(--bad); font-weight:600; }
  pre.input { background:var(--panel); border:1px solid var(--line); border-radius:6px; padding:.7rem .8rem;
              white-space:pre-wrap; word-break:break-word; font-size:.8rem; max-height:16rem; overflow:auto; margin:0; }
  table { border-collapse:collapse; width:100%; font-size:.84rem; margin-top:.5rem; }
  th,td { text-align:left; padding:.35rem .5rem; border-bottom:1px solid var(--line); vertical-align:top; }
  th { color:var(--muted); font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; }
  td.num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
  tr.top td { background:#eef3fe; }
  details { margin-top:.7rem; }
  summary { cursor:pointer; font-size:.85rem; color:var(--muted); }
  form.annotate { margin-top:.9rem; padding-top:.9rem; border-top:1px solid var(--line);
                  display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
  form.annotate input[type=text] { padding:.4rem .55rem; border:1px solid var(--line); border-radius:5px; font:inherit; font-size:.85rem; }
  form.annotate input[name=label] { min-width:15rem; margin-left:.4rem; }
  form.annotate input[name=reason] { flex:1; min-width:14rem; }
  form.annotate .answer { font-size:.8rem; color:var(--muted); white-space:nowrap; }
  .hint { font-size:.78rem; color:var(--muted); margin:.5rem 0 0; }
  .note-block { border-left:3px solid var(--line); padding:.1rem 0 .1rem .7rem; margin:0 0 .7rem; }
  .note-heading { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); margin-bottom:.15rem; }
  .note-body { font-size:.85rem; white-space:pre-wrap; }
  button { font:inherit; font-size:.85rem; padding:.4rem .8rem; border-radius:5px; border:1px solid var(--line);
           background:#fff; cursor:pointer; }
  button.up { border-color:#9ed7b6; color:var(--good); }
  button.down { border-color:#e6a9ad; color:var(--bad); }
  button:hover { background:var(--panel); }
  .empty { text-align:center; color:var(--muted); padding:3rem 0; }
  .pager { margin-top:1.5rem; }
  .pager a, .pager span { font-size:.85rem; }
</style>
</head>
<body>
<div class="wrap">

  <h1>Review AI decisions</h1>
  <p class="sub">
    {{ $outstanding }} awaiting review · {{ $reviewed }} already reviewed.
    Say whether each decision was right, and what the right action was. Every answer you
    record goes into the set the eval scores models against.
  </p>

  @if(session('status'))
    <div class="status">{{ session('status') }}</div>
  @endif

  <form class="filters" method="get">
    <label>Prompt <input type="text" name="prompt" value="{{ $promptId }}" placeholder="decide_next_action"></label>
    <label>Tag <input type="text" name="tag" value="{{ $tag }}" placeholder="any"></label>
    <label><input type="checkbox" name="all" value="1" @checked($includeReviewed)> include already reviewed</label>
    <button type="submit">Filter</button>
  </form>

  @forelse($requests as $aiRequest)
    @php
      $structured = is_array($aiRequest->structured_response) ? $aiRequest->structured_response : [];
      $ranked = StructuredResponsePresenter::ranked($structured);
      $suggested = StructuredResponsePresenter::topKey($structured);
      $latest = $aiRequest->annotations->sortByDesc('id')->first();
      $requestContext = $context[$aiRequest->id] ?? null;
    @endphp

    <div class="card">
      <h2>Request #{{ $aiRequest->id }} — {{ $aiRequest->prompt_id }}</h2>
      <div class="meta">
        {{ $aiRequest->provider }}:{{ $aiRequest->model }}
        · {{ number_format($aiRequest->duration_ms) }} ms
        @if($aiRequest->created_at) · {{ $aiRequest->created_at->diffForHumans() }} @endif
        @foreach($requestContext?->links ?? [] as $link)
          · <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer">{{ $link['label'] }}</a>
        @endforeach
        @if($latest)
          · last answer:
          @if($latest->label)
            <span class="answered">{{ $latest->label }}</span>
          @else
            <span class="unanswered">none recorded</span>
          @endif
        @endif
      </div>

      @foreach($requestContext?->notes ?? [] as $heading => $note)
        <div class="note-block">
          <div class="note-heading">{{ $heading }}</div>
          <div class="note-body">{{ $note }}</div>
        </div>
      @endforeach

      @if($ranked)
        @foreach(StructuredResponsePresenter::extras($structured) as $field => $value)
          <p class="meta" style="margin-bottom:.35rem"><strong>{{ $field }}:</strong> {{ $value }}</p>
        @endforeach
        <table>
          <thead><tr><th>Option</th><th style="width:5rem">Likelihood</th><th>Reasoning</th></tr></thead>
          <tbody>
            @foreach($ranked as $index => $row)
              <tr class="{{ $index === 0 ? 'top' : '' }}">
                <td><strong>{{ $row['key'] }}</strong></td>
                <td class="num">{{ number_format($row['likelihood'], 0) }}</td>
                <td>{{ $row['reasoning'] }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @elseif($structured !== [])
        <pre class="input">{{ json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
      @elseif($aiRequest->response_text)
        <pre class="input">{{ $aiRequest->response_text }}</pre>
      @endif

      {{-- Loaded on demand: these prompts embed the whole ticket, attachments
           and all, so fetching every one up front would exhaust memory. --}}
      <details data-input-url="{{ route('ai-workflow.review.input', $aiRequest) }}">
        <summary>Show the input this decision was made from</summary>
        <pre class="input" style="margin-top:.5rem" data-input>Loading…</pre>
      </details>

      <form class="annotate" method="post" action="{{ route('ai-workflow.review.annotate', $aiRequest) }}">
        @csrf
        <label class="answer">Right answer
          <input type="text" name="label" value="{{ $latest->label ?? $suggested }}">
        </label>
        <input type="text" name="reason" placeholder="why (optional)">
        <button class="up" type="submit">Save answer</button>
      </form>
      <p class="hint">
        The box says what the right answer was, and starts on the one the model picked.
        Change it where the model was wrong, leave it where it was right, and clear it to
        record that you looked and could not settle on an answer.
      </p>
    </div>
  @empty
    <p class="empty">Nothing to review here. Try “include already reviewed”, or widen the filters.</p>
  @endforelse

  <div class="pager">{{ $requests->links() }}</div>

</div>

<script>
  // Fetch each prompt only when its disclosure is first opened.
  document.querySelectorAll('details[data-input-url]').forEach(function (details) {
    details.addEventListener('toggle', function () {
      if (!details.open || details.dataset.loaded) {
        return;
      }

      details.dataset.loaded = '1';
      var target = details.querySelector('[data-input]');

      fetch(details.dataset.inputUrl)
        .then(function (response) {
          if (!response.ok) {
            throw new Error('HTTP ' + response.status);
          }
          return response.text();
        })
        .then(function (text) { target.textContent = text; })
        .catch(function (error) {
          details.dataset.loaded = '';
          target.textContent = 'Could not load the input: ' + error.message;
        });
    });
  });
</script>
</body>
</html>
