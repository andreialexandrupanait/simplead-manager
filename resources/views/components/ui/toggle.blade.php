@props(['enabled' => false])

<button type="button"
        x-data="{ on: @js($enabled) }"
        @click="on = !on; $dispatch('input', on)"
        :class="on ? 'bg-accent-600' : 'bg-gray-200 dark:bg-gray-600'"
        {{ $attributes->merge(['class' => 'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-accent-500 focus:ring-offset-2']) }}
        role="switch"
        :aria-checked="on">
    <span aria-hidden="true" :class="on ? 'translate-x-5' : 'translate-x-0'"
          class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"></span>
</button>
