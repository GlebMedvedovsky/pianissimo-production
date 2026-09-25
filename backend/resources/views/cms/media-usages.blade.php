<div class="space-y-3">
    @if(!$usages)<p>Diese Datei wird derzeit nicht verwendet.</p>@else
        <p>Diese Datei kann noch nicht gelöscht werden. Sie wird aktuell verwendet in:</p>
        <ul class="space-y-2">
            @foreach($usages as $usage)
                <li><strong>{{ $usage['location'] }}</strong> → {{ $usage['slot'] }} @if($usage['required'])<span>· Ersatz erforderlich</span>@endif
                    <a class="fi-link" href="{{ $usage['url'] }}">Öffnen</a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
