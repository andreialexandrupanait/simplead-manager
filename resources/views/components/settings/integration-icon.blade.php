@props(['name'])

{{-- Brand marks for the integration list.

     Google, Cloudflare and Dropbox keep their real logos — those are
     recognisable identity, not decoration. The rest used to each carry a
     different arbitrary tint (amber, green, accent, yellow, grey) which read
     as meaning and carried none; they are neutral now. --}}
@switch($name)
    @case('anthropic')
        <svg aria-hidden="true" class="h-5 w-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5"/></svg>
        @break

    @case('openai')
        <svg aria-hidden="true" class="h-5 w-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6.75v10.5a2.25 2.25 0 002.25 2.25z"/></svg>
        @break

    @case('openapi')
        <svg aria-hidden="true" class="h-5 w-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
        @break

    @case('google')
        <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
        @break

    @case('cloudflare')
        <svg aria-hidden="true" class="h-5 w-5" fill="#F6821F" viewBox="0 0 24 24"><path d="M16.51 15.45c.15-.51.08-.98-.2-1.34a1.13 1.13 0 0 0-.93-.42H7.64c-.1 0-.18-.04-.22-.12a.24.24 0 0 1 0-.24c.04-.08.12-.15.22-.17a.56.56 0 0 0 .1-.02h8.03c1.08-.05 2.24-.87 2.65-1.95l.52-1.37c.02-.05.03-.1.03-.16 0-.04.02-.08.02-.12a4.35 4.35 0 0 0-8.37-1.43 2.2 2.2 0 0 0-1.53-.36 2.26 2.26 0 0 0-1.93 1.88 3.27 3.27 0 0 0-2.84 3.2c0 .1.01.2.02.3h-.08A2.57 2.57 0 0 0 2 15.67c0 .13.01.25.03.37.02.1.1.17.2.17h13.85c.1 0 .2-.07.23-.17l.2-.59zM19.35 11.06a.17.17 0 0 0-.17.13l-.24.82c-.15.51-.08.98.2 1.34.24.31.63.47 1.07.48l1.53.04c.1 0 .18.04.22.12a.24.24 0 0 1 0 .24c-.04.08-.12.15-.22.17a.56.56 0 0 0-.1.02l-1.58.04c-1.08.05-2.24.87-2.65 1.95l-.14.39c-.03.08.02.15.1.15h5.2c.1 0 .17-.06.2-.15a5.26 5.26 0 0 0-3.42-5.74z"/></svg>
        @break

    @case('postmark')
        <svg aria-hidden="true" class="h-5 w-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        @break

    @case('dropbox')
        <svg aria-hidden="true" class="h-5 w-5" fill="#0061FE" viewBox="0 0 24 24"><path d="M6 2l6 3.75L6 9.5 0 5.75zm12 0l6 3.75-6 3.75-6-3.75zM0 13.25L6 9.5l6 3.75L6 17zm12-3.75l6-3.75 6 3.75-6 3.75zm-5.97 4.49L6 14l-.03-.01L0 17.24v1.52l6.03-3.75L12 18.76v-1.52l-5.97-3.25zm11.94 0L12 17.24v1.52l5.97-3.25L24 18.76v-1.52l-6.03-3.25z"/></svg>
        @break

    @case('unsplash')
        <svg aria-hidden="true" class="h-5 w-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        @break

@endswitch
