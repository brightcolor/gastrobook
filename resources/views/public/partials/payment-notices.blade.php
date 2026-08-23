{{-- Die Hinweise, die der Rueckweg vom Zahlungsanbieter in die Sitzung legt.
     Sie standen doppelt in den beiden Verwaltungsseiten; eine davon hatte sie
     ueber mehrere Versionen gar nicht. --}}
@foreach(['payment_already_settled', 'payment_amount_mismatch'] as $zahlungshinweis)
    @if(session($zahlungshinweis))
        <div class="mt-4 rounded-xl bg-amber-50 p-3.5 text-sm text-amber-900">
            <span class="text-base">⚠️</span> {{ session($zahlungshinweis) }}
        </div>
    @endif
@endforeach
