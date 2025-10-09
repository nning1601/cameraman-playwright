function factorial(n){
    if (n === 0){
        return  true;
    }
    return n * factorial(n - 1);
}
console.log(factorial(6)); // Output: 120