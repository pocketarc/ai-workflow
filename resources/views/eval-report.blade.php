@php
    /** @var \AiWorkflow\Eval\EvalReport $report */
    $pct = static fn (?float $value): string => $value === null ? '—' : number_format($value * 100, 1).'%';
    $money = static fn (?float $value): string => $value === null ? '—' : '$'.number_format($value, $value < 1 ? 4 : 2);
    $ms = static fn (?float $value): string => $value === null ? '—' : number_format($value).' ms';
    $num = static fn (?float $value, int $dp = 3): string => $value === null ? '—' : number_format($value, $dp);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Eval report — {{ $report->runName }}</title>
<style>
  :root {
    --ink: #16191d;
    --muted: #5c6470;
    --line: #e3e6ea;
    --bg: #ffffff;
    --panel: #f7f8fa;
    --good: #1a7f4b;
    --bad: #b4232c;
    --accent: #2b5fd9;
    --warn-bg: #fff8e6;
    --warn-line: #e8c96a;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 2.5rem 1.5rem 5rem;
    font: 15px/1.55 ui-sans-serif, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    color: var(--ink); background: var(--bg);
  }
  .wrap { max-width: 1100px; margin: 0 auto; }
  h1 { font-size: 1.6rem; margin: 0 0 .3rem; letter-spacing: -.01em; }
  h2 { font-size: 1.15rem; margin: 2.75rem 0 .9rem; letter-spacing: -.01em; }
  h3 { font-size: .95rem; margin: 1.5rem 0 .5rem; }
  .sub { color: var(--muted); margin: 0 0 .15rem; font-size: .9rem; }
  table { border-collapse: collapse; width: 100%; font-size: .875rem; }
  th, td { text-align: right; padding: .5rem .6rem; border-bottom: 1px solid var(--line); white-space: nowrap; }
  th:first-child, td:first-child { text-align: left; white-space: normal; }
  thead th { font-weight: 600; color: var(--muted); border-bottom: 2px solid var(--line); font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
  tbody tr:hover { background: var(--panel); }
  .tag { display: inline-block; font-size: .68rem; text-transform: uppercase; letter-spacing: .05em;
         padding: .1rem .38rem; border-radius: 3px; background: var(--panel); color: var(--muted); border: 1px solid var(--line); margin-left: .35rem; vertical-align: 1px; }
  .up { color: var(--good); } .down { color: var(--bad); }
  .note { color: var(--muted); font-size: .82rem; }
  .banner { background: var(--warn-bg); border: 1px solid var(--warn-line); border-radius: 6px; padding: .7rem .9rem; margin: 1rem 0; font-size: .85rem; }
  .banner ul { margin: .4rem 0 0; padding-left: 1.1rem; }
  .headline { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 1.1rem 1.2rem; margin: 1.25rem 0 0; }
  .headline strong { font-size: 1.25rem; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-top: .8rem; }
  .stat .k { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
  .stat .v { font-size: 1.05rem; font-weight: 600; }
  .matrix-wrap { overflow-x: auto; }
  .matrix { font-size: .8rem; width: auto; min-width: 100%; }
  .matrix td, .matrix th { text-align: center; padding: .35rem .5rem; font-variant-numeric: tabular-nums; }
  .matrix th.rowhead, .matrix td.rowhead { text-align: left; font-weight: 600; white-space: nowrap; }
  /* Nine long class names will not fit across a page, so the column headers
     stand on end and the whole matrix stays readable without truncation. */
  .matrix thead th.col { writing-mode: vertical-rl; transform: rotate(180deg);
                         white-space: nowrap; vertical-align: bottom; text-transform: none;
                         letter-spacing: 0; font-size: .78rem; padding: .3rem .15rem .5rem; }
  .matrix .diag { outline: 2px solid var(--good); outline-offset: -2px; }
  .matrix .zero { color: #c3c8cf; }
  details { margin-top: .6rem; border: 1px solid var(--line); border-radius: 6px; padding: .55rem .8rem; }
  summary { cursor: pointer; font-weight: 600; font-size: .9rem; }
  .bar { height: 6px; border-radius: 3px; background: var(--accent); display: inline-block; vertical-align: middle; }
  code { background: var(--panel); padding: .1rem .3rem; border-radius: 3px; font-size: .85em; }
  footer { margin-top: 3.5rem; padding-top: 1.2rem; border-top: 1px solid var(--line); color: var(--muted); font-size: .82rem; }
  footer li { margin-bottom: .3rem; }
</style>
</head>
<body>
<div class="wrap">

  <h1>{{ $report->runName }}</h1>
  <p class="sub">
    {{ $report->requestCount }} golden {{ Str::plural('decision', $report->requestCount) }}
    · {{ count($report->models) }} {{ Str::plural('model', count($report->models)) }}
    @if($report->runCreatedAt) · {{ $report->runCreatedAt->format('j M Y, H:i') }} @endif
  </p>
  <p class="sub">Run <code>{{ $report->runId }}</code></p>

  @if($report->hasUnlabelledRequests() || $report->modelsMissingPricing !== [] || $report->isTruncated() || $report->unreliableModels() !== [])
    <div class="banner">
      <strong>Read with care</strong>
      <ul>
        @foreach($report->unreliableModels() as $model)
          <li>
            <strong>{{ $model->model }} failed on {{ $model->errors }} of {{ $model->scored }} items.</strong>
            Its score reflects calls that did not complete, not the quality of its answers.
            Fix the integration before reading anything into the number.
          </li>
        @endforeach
        @if($report->hasUnlabelledRequests())
          <li>{{ $report->requestCount - $report->labelledCount }} of {{ $report->requestCount }} requests have no human label, so they are excluded from accuracy.</li>
        @endif
        @if($report->modelsMissingPricing !== [])
          <li>No pricing configured for {{ implode(', ', $report->modelsMissingPricing) }} — cost is shown as “—” rather than estimated.</li>
        @endif
        @if($report->isTruncated())
          <li>Showing {{ count($report->decisions) }} of {{ $report->decisionsTotal }} decisions in the drill-down (models that disagreed are listed first).</li>
        @endif
      </ul>
    </div>
  @endif

  @if($best = $report->best())
    <div class="headline">
      <div class="k note">Best agreement with human labels</div>
      <div><strong>{{ $best->model }}</strong> — {{ $pct($best->accuracy) }} accuracy</div>
      <div class="grid">
        <div class="stat"><div class="k">Cohen’s κ</div><div class="v">{{ $num($best->kappa) }}</div></div>
        <div class="stat"><div class="k">Macro-F1</div><div class="v">{{ $num($best->macroF1) }}</div></div>
        <div class="stat"><div class="k">Cost / 1k decisions</div><div class="v">{{ $money($best->costPerThousand()) }}</div></div>
        <div class="stat"><div class="k">Cost / correct</div><div class="v">{{ $money($best->costPerCorrectDecision()) }}</div></div>
        <div class="stat"><div class="k">Median latency</div><div class="v">{{ $ms($best->medianLatencyMs) }}</div></div>
      </div>
    </div>
  @endif

  <h2>Model comparison</h2>
  <table>
    <thead>
      <tr>
        <th>Model</th>
        <th>Accuracy</th>
        <th>95% CI</th>
        <th>Δ vs baseline</th>
        <th>p (McNemar)</th>
        <th>κ</th>
        <th>Macro-F1</th>
        <th>Blended</th>
        <th>Cost / 1k</th>
        <th>Median</th>
        <th>p95</th>
        <th>Errors</th>
      </tr>
    </thead>
    <tbody>
      @foreach($report->models as $model)
        <tr>
          <td>
            {{ $model->model }}
            @if($model->isBaseline)<span class="tag">baseline</span>@endif
            @if($model->overlapsBaselineInterval === true)<span class="tag">CI overlaps baseline</span>@endif
          </td>
          <td>{{ $pct($model->accuracy) }}</td>
          <td class="note">
            @if($model->accuracyInterval)
              {{ $pct($model->accuracyInterval['lower']) }}–{{ $pct($model->accuracyInterval['upper']) }}
            @else — @endif
          </td>
          <td class="{{ ($model->accuracyDelta ?? 0) > 0 ? 'up' : (($model->accuracyDelta ?? 0) < 0 ? 'down' : '') }}">
            @if($model->accuracyDelta === null) — @else
              {{ $model->accuracyDelta > 0 ? '+' : '' }}{{ number_format($model->accuracyDelta * 100, 1) }} pp
            @endif
          </td>
          <td class="note">
            @if($model->mcNemarP !== null)
              {{ $model->mcNemarP < 0.001 ? '<0.001' : $num($model->mcNemarP) }} ({{ $model->winsVsBaseline }}–{{ $model->lossesVsBaseline }})
            @elseif($model->winsVsBaseline !== null)
              — ({{ $model->winsVsBaseline }}–{{ $model->lossesVsBaseline }})
            @else — @endif
          </td>
          <td>{{ $num($model->kappa) }}</td>
          <td>{{ $num($model->macroF1) }}</td>
          <td>{{ $num($model->blendedScore) }}</td>
          <td>{{ $money($model->costPerThousand()) }}</td>
          <td>{{ $ms($model->medianLatencyMs) }}</td>
          <td>{{ $ms($model->p95LatencyMs) }}</td>
          <td>{{ $model->errors ?: '—' }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
  <p class="note">
    Accuracy is exact agreement with the human-approved answer, with a Wilson 95% interval.
    “Blended” is the mean judge score, which may award partial credit. Models whose interval
    overlaps the baseline’s are tagged <em>CI overlaps baseline</em> — a caution that the set
    may be too small to separate them, not a paired significance test. That test is
    “p (McNemar)”: an exact binomial test over the requests exactly one of the pair got
    right, shown in brackets as wins–losses against the baseline. Below 0.05 is
    conventionally read as evidence against equal accuracy for that pair — the values are
    unadjusted for comparing several models at once. A dash means the pair never disagreed.
  </p>

  @foreach($report->models as $model)
    <h2>{{ $model->model }}</h2>
    <p class="sub">
      {{ $model->correct }}/{{ $model->labelled }} correct
      · {{ number_format($model->inputTokens) }} in / {{ number_format($model->outputTokens) }} out{{ $model->thoughtTokens > 0 ? ' / '.number_format($model->thoughtTokens).' thought' : '' }} tokens
      · total {{ $money($model->cost) }}
    </p>

    <h3>Confusion matrix</h3>
    @php $classes = $report->classes; @endphp
    <div class="matrix-wrap">
    <table class="matrix">
      <thead>
        <tr>
          <th class="rowhead">Human label ↓ / predicted →</th>
          @foreach($classes as $class)<th class="col">{{ $class }}</th>@endforeach
          <th>total</th>
        </tr>
      </thead>
      <tbody>
        @foreach($classes as $truth)
          @php $row = $model->confusion[$truth] ?? []; $rowTotal = array_sum($row); @endphp
          @if($rowTotal > 0)
            <tr>
              <td class="rowhead">{{ $truth }}</td>
              @foreach($classes as $predicted)
                @php
                  $count = $row[$predicted] ?? 0;
                  $share = $rowTotal > 0 ? $count / $rowTotal : 0;
                  $shade = $count === 0 ? 'transparent' : 'rgba(43, 95, 217, '.number_format(0.08 + ($share * 0.55), 3).')';
                @endphp
                <td class="{{ $truth === $predicted ? 'diag' : '' }} {{ $count === 0 ? 'zero' : '' }}"
                    style="background: {{ $shade }}">{{ $count ?: '·' }}</td>
              @endforeach
              <td class="note">{{ $rowTotal }}</td>
            </tr>
          @endif
        @endforeach
      </tbody>
    </table>
    </div>
    <p class="note">Rows are the human answer, columns what {{ $model->model }} chose. Off-diagonal cells are its mistakes.</p>

    <h3>Per-class performance</h3>
    <table>
      <thead>
        <tr><th>Class</th><th>Support</th><th>Precision</th><th>Recall</th><th>F1</th><th></th></tr>
      </thead>
      <tbody>
        @foreach($model->perClass as $class => $metrics)
          @if($metrics['support'] > 0)
            <tr>
              <td>{{ $class }}</td>
              <td>{{ $metrics['support'] }}</td>
              <td>{{ $num($metrics['precision']) }}</td>
              <td>{{ $num($metrics['recall']) }}</td>
              <td>{{ $num($metrics['f1']) }}</td>
              <td style="width:120px"><span class="bar" style="width: {{ number_format($metrics['f1'] * 100, 1) }}px"></span></td>
            </tr>
          @endif
        @endforeach
      </tbody>
    </table>
  @endforeach

  <h2>Decisions</h2>
  <p class="note">Items the models disagreed on are listed first — those are where the prompt is ambiguous or a model is weak.</p>
  @foreach($report->decisions as $decision)
    <details>
      <summary>
        #{{ $decision->requestId }} — human: {{ $decision->groundTruth ?? 'unlabelled' }}
        @if($decision->isContested())<span class="tag">disputed</span>@endif
      </summary>
      <table>
        <thead><tr><th>Model</th><th>Predicted</th><th>Score</th></tr></thead>
        <tbody>
          @foreach($decision->byModel as $model => $result)
            <tr>
              <td>{{ $model }}</td>
              <td class="{{ $result['correct'] ? 'up' : 'down' }}">{{ $result['predicted'] ?? '(no answer)' }}</td>
              <td>{{ $num($result['score'], 2) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <h3>Input</h3>
      <p class="note" style="white-space: pre-wrap">{{ $decision->input }}</p>
    </details>
  @endforeach

  <footer>
    <strong>Method</strong>
    <ul>
      <li>Each golden request is replayed against every model with the same messages and schema, so the comparison is like-for-like.</li>
      <li>Ground truth is the answer a human approved, not the original model's own choice.</li>
      <li>Accuracy uses a Wilson score interval, which stays valid on small eval sets where the normal approximation does not.</li>
      <li>Cohen's κ corrects for chance agreement; on a skewed label mix it is a fairer read than accuracy, and a majority-class guesser scores 0.</li>
      <li>Macro-F1 averages F1 across the classes that occur in the ground truth, so rare classes count as much as common ones.</li>
      <li>A model that returned no answer is counted as wrong and shown as <code>(no answer)</code>, never dropped from the denominator.</li>
      <li>Accuracy, κ and F1 come from deterministic label matching, so they carry no self-preference bias; the blended score comes from the run's configured judge, which may itself be an LLM.</li>
    </ul>
  </footer>

</div>
</body>
</html>
