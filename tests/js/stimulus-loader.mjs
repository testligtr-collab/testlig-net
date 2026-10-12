export async function resolve(specifier, context, nextResolve) {
    if ('@hotwired/stimulus' === specifier) {
        return {
            shortCircuit: true,
            url: new URL('./stimulus-stub.mjs', import.meta.url).href,
        };
    }

    return nextResolve(specifier, context);
}
