// Resolves `@hotwired/stimulus` to the tests' stub, the bundle installing no npm package of its own.
export async function resolve(specifier, context, next) {
    return specifier === '@hotwired/stimulus'
        ? { url: new URL('./stimulus.stub.mjs', import.meta.url).href, shortCircuit: true }
        : next(specifier, context);
}
