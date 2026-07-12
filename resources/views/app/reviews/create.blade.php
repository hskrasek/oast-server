<x-app-layout title="New review — oast">
    <section class="o-app-page" x-data="reviewSubmission">
        <header class="o-page-head"><div><p class="o-label">new council run</p><h1 class="o-headline">Review a specification</h1></div></header>
        <form class="o-review-form" action="{{ route('app.reviews.store') }}" method="POST" enctype="multipart/form-data" @submit.prevent="submit($el)">
            @csrf
            <div class="o-source-tabs" role="tablist" aria-label="Specification source">
                <button type="button" role="tab" :aria-selected="source === 'paste'" @click="source = 'paste'">Paste YAML or JSON</button>
                <button type="button" role="tab" :aria-selected="source === 'upload'" @click="source = 'upload'">Upload a file</button>
            </div>
            <label x-show="source === 'paste'" class="o-field">
                <span>Paste YAML or JSON</span>
                <textarea name="spec" rows="20" :disabled="source !== 'paste'" placeholder="openapi: 3.1.0">{{ old('spec') }}</textarea>
                <template x-for="message in (errors.spec || [])"><small class="o-error" x-text="message"></small></template>
            </label>
            <label x-show="source === 'upload'" class="o-field">
                <span>Upload a file</span>
                <input type="file" name="spec_file" :disabled="source !== 'upload'" accept=".yaml,.yml,.json,application/json,application/yaml,text/yaml">
                <small>JSON or YAML, up to 5 MiB. Original bytes and comments are retained.</small>
                <template x-for="message in (errors.spec_file || [])"><small class="o-error" x-text="message"></small></template>
            </label>
            <div class="o-review-options">
                <label class="o-field"><span>Mode</span><select name="mode">@foreach($modes as $mode)<option value="{{ $mode->value }}">{{ ucfirst($mode->value) }}</option>@endforeach</select></label>
                <label class="o-field"><span>Dimension</span><select name="dimension">@foreach($dimensions as $dimension)<option value="{{ $dimension->value }}">{{ str($dimension->value)->replace('-', ' ')->title() }}</option>@endforeach</select></label>
            </div>
            <p class="o-error" x-show="failure" x-text="failure" role="alert"></p>
            <button class="o-btn" type="submit" :disabled="submitting" x-text="submitting ? 'Starting…' : 'Start review'"></button>
        </form>
    </section>
</x-app-layout>
