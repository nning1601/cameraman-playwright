function performOperation(a, b, operation) {
return operation(a, b);
}
function add(x, y) {
return x + y;
}
function multiply(x, y) {
return x * y;
}
//Example usage:
console.log(performOperation(5, 3, add));
console.log(performOperation(5, 3, multiply));
